#include "Runtime.hpp"
#include <Geode/Geode.hpp>
#include <Geode/modify/MenuLayer.hpp>
#include <Geode/modify/PlayLayer.hpp>
#include <Geode/loader/GameEvent.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/ui/Notification.hpp>
#include <loader/LoaderImpl.hpp>
#include <algorithm>
#include <cmath>

using namespace geode::prelude;
namespace {
// Process lifetime, deliberately not destroyed from DllMain / the loader lock.
mucho::Runtime& runtime() { static auto instance = new mucho::Runtime; return *instance; }
unsigned long long sceneEpoch = 0;
bool started = false;

std::string safe(std::string text, std::size_t limit=150) {
    for (auto& c : text) if (static_cast<unsigned char>(c)<32 || c=='<' || c=='>') c=' ';
    if (text.size()>limit) text.resize(limit);
    return text;
}
std::string field(matjson::Value const& data, char const* name) { return data[name].asString().unwrapOr(""); }
void event(char const* name, matjson::Value data=matjson::Value::object()) {
    runtime().emit(name, data.dump(), sceneEpoch);
}
void start() {
    if (started) return;
    started=true;
    if (LoaderImpl::get()->isSafeMode() || LoaderImpl::get()->isForwardCompatMode() ||
        Loader::get()->getLaunchFlag("disable-mucho-python")) return;
    auto root=dirs::getGeodeDir()/"mucholoader";
    runtime().start({root/"sdk",root/"plugins",root/"python"});
}

class MuchoPump : public CCNode {
    bool m_ready=false;
    void tick(float) {
        auto status=runtime().status();
        if (!m_ready && status.state=="ready") { m_ready=true; event("menu.ready"); }
        for (auto const& command : runtime().drain(24)) {
            if (!runtime().current(command) || (command.scene && command.scene!=sceneEpoch)) continue;
            auto parsed=matjson::parse(command.payload);
            if (!parsed || !parsed.unwrap().isObject()) continue;
            auto data=parsed.unwrap();
            if (command.operation=="notice") {
                auto message=safe(field(data,"message"),180);
                if (!message.empty()) Notification::create(message,NotificationIcon::Info,2.f)->show();
            } else if (command.operation=="pulse") {
                auto layer=PlayLayer::get();
                if (!layer || command.scene==0) continue;
                auto color=field(data,"color");
                if (color.size()!=7 || color.front()!='#' || !std::all_of(color.begin()+1,color.end(),[](char c){
                    return (c>='0'&&c<='9')||(c>='a'&&c<='f')||(c>='A'&&c<='F');
                })) continue;
                auto duration=data["duration"].asDouble().unwrapOr(0);
                auto opacity=data["opacity"].asInt().unwrapOr(-1);
                if (!std::isfinite(duration)||duration<0.05||duration>3||opacity<0||opacity>150) continue;
                // Reuse one overlay per level: an event flood cannot allocate one node per command.
                auto overlay=typeinfo_cast<CCLayerColor*>(layer->getChildByID("mucholoader/pulse"));
                if (!overlay) {
                    overlay=CCLayerColor::create({0,0,0,0});
                    overlay->setID("mucholoader/pulse"); layer->addChild(overlay,10000);
                }
                auto rgb=std::stoul(color.substr(1),nullptr,16);
                overlay->stopAllActions();
                overlay->setContentSize(CCDirector::sharedDirector()->getWinSize());
                overlay->setColor({static_cast<GLubyte>(rgb>>16),static_cast<GLubyte>((rgb>>8)&255),static_cast<GLubyte>(rgb&255)});
                overlay->setOpacity(static_cast<GLubyte>(opacity));
                overlay->runAction(CCFadeTo::create(static_cast<float>(duration),0));
            }
        }
    }
public:
    static void install() {
        auto node=new MuchoPump;
        node->init();
        CCDirector::sharedDirector()->getScheduler()->scheduleSelector(schedule_selector(MuchoPump::tick),node,0.05f,false);
        // Scheduler retains the target; the process-lifetime runtime owns no scene nodes.
        node->release();
    }
};

class MuchoHub : public Popup {
    CCNode* m_content=nullptr;
    std::string m_signature;
    std::vector<matjson::Value> m_items;
    std::size_t m_page=0;
    bool m_buttons=false;
    void label(std::string text,float x,float y,float width,float scale=.38f) {
        auto node=CCLabelBMFont::create(safe(std::move(text),180).c_str(),"bigFont.fnt");
        node->setScale(scale);
        if (node->getScaledContentSize().width>width) node->setScale(width/node->getContentSize().width);
        node->setPosition({x,y}); m_content->addChild(node);
    }
    CCMenuItemSpriteExtra* button(CCMenu* menu,char const* text,SEL_MenuHandler callback,float x,float y,float width,int tag=0) {
        auto sprite=ButtonSprite::create(text);
        auto node=CCMenuItemSpriteExtra::create(sprite,this,callback);
        float scale=std::min(.48f,width/node->getContentSize().width);
        node->setScale(scale); node->m_baseScale=scale;
        node->setTag(tag); node->setPosition({x,y}); menu->addChild(node); return node;
    }
    void refresh(float) {
        auto status=runtime().status();
        auto signature=status.state+status.detail+status.plugins+std::to_string(m_page)+(m_buttons?"B":"P");
        if (signature==m_signature) return;
        m_signature=std::move(signature);
        m_content->removeAllChildren(); m_items.clear();
        auto menu=CCMenu::create(); menu->setPosition({0,0}); m_content->addChild(menu);
        label("Native compatibility: Geode 5.11.1 / GD 2.2081",210,237,375,.30f);
        label("Python: "+status.state+(status.detail.empty()?"":" - "+status.detail),210,220,375,.30f);
        button(menu,m_buttons?"Plugins":"Buttons",menu_selector(MuchoHub::onTab),64,196,85);
        button(menu,"Rescan",menu_selector(MuchoHub::onRescan),162,196,85);
        button(menu,"Folder",menu_selector(MuchoHub::onFolder),257,196,85);
        button(menu,"Logs",menu_selector(MuchoHub::onLogs),350,196,85);
        auto parsed=matjson::parse(status.plugins);
        std::vector<matjson::Value> plugins;
        if (parsed && parsed.unwrap().isArray()) plugins=parsed.unwrap().asArray().unwrap();
        if (m_buttons) {
            for (auto const& plugin:plugins) {
                if (field(plugin,"status")!="loaded" || !plugin["buttons"].isArray()) continue;
                for (auto entry:plugin["buttons"].asArray().unwrap()) {
                    entry["owner"]=field(plugin,"id"); entry["generation"]=plugin["generation"];
                    m_items.push_back(std::move(entry));
                }
            }
        } else m_items=std::move(plugins);
        auto pages=std::max(std::size_t{1},(m_items.size()+3)/4);
        m_page=std::min(m_page,pages-1);
        for (std::size_t row=0;row<4;++row) {
            auto index=m_page*4+row;
            if (index>=m_items.size()) break;
            auto const& item=m_items[index]; float y=162.f-static_cast<float>(row)*34.f;
            if (m_buttons) {
                label(field(item,"owner"),105,y,160,.34f);
                auto title=safe(field(item,"title"),32);
                button(menu,title.c_str(),menu_selector(MuchoHub::onRun),295,y,170,static_cast<int>(index));
            } else {
                label(field(item,"name"),115,y+6,190,.38f);
                label(field(item,"status")+"  v"+field(item,"version"),115,y-7,190,.28f);
                auto enabled=item["enabled"].asBool().unwrapOr(false);
                auto invalid=field(item,"status")=="invalid";
                button(menu,enabled?"Off":"On",menu_selector(MuchoHub::onToggle),254,y,47,static_cast<int>(index))->setEnabled(!invalid);
                button(menu,"Reload",menu_selector(MuchoHub::onReload),310,y,62,static_cast<int>(index))->setEnabled(!invalid);
                button(menu,"Info",menu_selector(MuchoHub::onInfo),371,y,46,static_cast<int>(index));
            }
        }
        if (m_items.empty()) label(m_buttons?"No plugin buttons":"Put Python plugins in the plugins folder",210,125,370,.36f);
        button(menu,"<",menu_selector(MuchoHub::onPrevious),110,39,40);
        label(std::to_string(m_page+1)+" / "+std::to_string(pages),210,39,80,.34f);
        button(menu,">",menu_selector(MuchoHub::onNext),310,39,40);
        label("Python callbacks run on a worker. Game commands run natively.",210,17,385,.26f);
    }
    matjson::Value selected(CCObject* sender) {
        int tag=static_cast<CCMenuItemSpriteExtra*>(sender)->getTag();
        return tag>=0 && static_cast<std::size_t>(tag)<m_items.size()?m_items[tag]:matjson::Value::object();
    }
    void control(char const* operation,matjson::Value item) {
        matjson::Value data=matjson::Value::object(); data["operation"]=operation; data["id"]=field(item,"id");
        if (!runtime().control(data.dump())) Notification::create("Python runtime is busy or unavailable",NotificationIcon::Error)->show();
    }
    void onToggle(CCObject* sender) { auto item=selected(sender); control(item["enabled"].asBool().unwrapOr(false)?"disable":"enable",item); }
    void onReload(CCObject* sender) { control("reload",selected(sender)); }
    void onRescan(CCObject*) { control("rescan",matjson::Value::object()); }
    void onFolder(CCObject*) { utils::file::openFolder(dirs::getGeodeDir()/"mucholoader"/"plugins"); }
    void onInfo(CCObject* sender) {
        auto item=selected(sender); auto text=field(item,"id")+" | "+field(item,"status")+"\n"+field(item,"error");
        if (field(item,"error").empty()) text+="No errors recorded.";
        FLAlertLayer::create("Python plugin",safe(text,700).c_str(),"OK")->show();
    }
    void onLogs(CCObject*) {
        auto status=runtime().status(); std::string text;
        auto begin=status.logs.size()>7?status.logs.size()-7:0;
        for (auto i=begin;i<status.logs.size();++i) text+=safe(status.logs[i],90)+"\n";
        text+="Dropped events: "+std::to_string(status.droppedEvents)+" / commands: "+std::to_string(status.droppedCommands);
        FLAlertLayer::create("MuchoLoader logs",text.c_str(),"OK")->show();
    }
    void onRun(CCObject* sender) { event("ui.button",selected(sender)); }
    void onTab(CCObject*) { m_buttons=!m_buttons; m_page=0; }
    void onPrevious(CCObject*) { if (m_page) --m_page; }
    void onNext(CCObject*) { if ((m_page+1)*4<m_items.size()) ++m_page; }
    bool init() {
        if (!Popup::init(420,280)) return false;
        setTitle("MuchoLoader 0.1.0");
        m_content=CCNode::create(); m_mainLayer->addChild(m_content);
        refresh(0); schedule(schedule_selector(MuchoHub::refresh),.5f); return true;
    }
public:
    static MuchoHub* create() { auto node=new MuchoHub; if(node->init()){node->autorelease();return node;} delete node;return nullptr; }
};
}

class $modify(MuchoMenu,MenuLayer) {
    static void onModify(auto& self) { GEODE_FORWARD_COMPAT_DISABLE_HOOKS_INNER("Mucho UI disabled in forward compatibility mode") }
    bool init() {
        if (!MenuLayer::init()) return false;
        ++sceneEpoch;
        auto menu=typeinfo_cast<CCMenu*>(getChildByID("bottom-menu"));
        if (menu && !Loader::get()->getLaunchFlag("disable-custom-menu")) {
            auto sprite=ButtonSprite::create("MUCHO");
            auto item=CCMenuItemSpriteExtra::create(sprite,this,menu_selector(MuchoMenu::onMucho));
            item->setScale(.45f); item->m_baseScale=.45f; item->setID("mucholoader/hub");
            menu->addChild(item); menu->updateLayout();
        }
        event("menu.ready"); return true;
    }
    void onMucho(CCObject*) { if (auto hub=MuchoHub::create()) hub->show(); }
};
class $modify(MuchoPlay,PlayLayer) {
    static void onModify(auto& self) { GEODE_FORWARD_COMPAT_DISABLE_HOOKS_INNER("Mucho events disabled in forward compatibility mode") }
    bool init(GJGameLevel* level,bool useReplay,bool dontCreateObjects) {
        if (!PlayLayer::init(level,useReplay,dontCreateObjects)) return false;
        ++sceneEpoch; matjson::Value data=matjson::Value::object();
        data["level_id"]=level?static_cast<int>(level->m_levelID):0;
        event("level.start",data); return true;
    }
    void destroyPlayer(PlayerObject* player,GameObject* cause) {
        PlayLayer::destroyPlayer(player,cause);
        event("level.death");
    }
    void levelComplete() { PlayLayer::levelComplete(); event("level.complete"); }
    void resetLevel() {
        ++sceneEpoch;
        PlayLayer::resetLevel();
        if (auto overlay=getChildByID("mucholoader/pulse")) overlay->removeFromParent();
        matjson::Value data=matjson::Value::object();
        data["level_id"]=m_level?static_cast<int>(m_level->m_levelID):0;
        event("level.start",data);
    }
    void onExit() { ++sceneEpoch; PlayLayer::onExit(); }
};
$on_game(Loaded) { start(); MuchoPump::install(); }
$on_game(Exiting) { runtime().requestStop(); }
