#include "MuchoMotion.hpp"
#include "MuchoClanRules.hpp"
#include <Geode/modify/CCMenuItemSpriteExtra.hpp>
#include <Geode/binding/PlayLayer.hpp>
#include <array>
#include <vector>

using namespace geode::prelude;

namespace mucho::motion {
namespace {
    constexpr int TRANSFORM = 0x4D4301, PAINT = 0x4D4302, NUMBER = 0x4D4303, PRESS = 0x4D4304, LOOP = 0x4D4305;
    std::vector<WeakRef<CCNode>> activeNodes;
    bool reserve(CCNode* node) {
        if (!node || mode() == rules::Mode::Off) return false;
        std::erase_if(activeNodes,[](auto const& weak) {
            auto n = weak.lock();
            return !n || (!n->getActionByTag(TRANSFORM) && !n->getActionByTag(PAINT) &&
                !n->getActionByTag(NUMBER) && !n->getActionByTag(PRESS) && !n->getActionByTag(LOOP));
        });
        for (auto const& weak : activeNodes) if (weak.lock().data() == node) return true;
        if (!rules::available(static_cast<unsigned>(activeNodes.size()))) return false;
        activeNodes.emplace_back(node); return true;
    }
    void action(CCNode* node, CCAction* action, int tag) {
        node->stopActionByTag(tag); action->setTag(tag); node->runAction(action);
    }
    class Rest final : public CCObject {
    public:
        CCPoint position; float x = 1, y = 1; int opacity = -1;
        static Rest* get(CCNode* node) {
            auto value = static_cast<Rest*>(node->getUserObject("mucho-motion-rest"));
            if (!value) {
                value = new Rest(); value->position = node->getPosition();
                value->x = node->getScaleX(); value->y = node->getScaleY();
                node->setUserObject("mucho-motion-rest",value); value->release();
            }
            return value;
        }
        void settle(CCNode* node, bool restoreScale = true) const {
            node->stopActionByTag(TRANSFORM); node->setPosition(position);
            if(restoreScale) { node->setScaleX(x); node->setScaleY(y); }
        }
    };
    void fade(CCNode* node, float duration, float delay = 0) {
        bool supported = typeinfo_cast<CCLabelBMFont*>(node) || typeinfo_cast<CCSprite*>(node) || typeinfo_cast<CCLayerColor*>(node);
        if (!supported) return;
        auto rest = Rest::get(node);
        GLubyte opacity = 255;
        if (auto label = typeinfo_cast<CCLabelBMFont*>(node)) opacity = label->getOpacity();
        else if (auto sprite = typeinfo_cast<CCSprite*>(node)) opacity = sprite->getOpacity();
        else if (auto color = typeinfo_cast<CCLayerColor*>(node)) opacity = color->getOpacity();
        if (rest->opacity < 0) rest->opacity = opacity;
        opacity = static_cast<GLubyte>(rest->opacity);
        node->stopActionByTag(PAINT);
        if (auto label = typeinfo_cast<CCLabelBMFont*>(node)) label->setOpacity(0);
        else if (auto sprite = typeinfo_cast<CCSprite*>(node)) sprite->setOpacity(0);
        else if (auto color = typeinfo_cast<CCLayerColor*>(node)) color->setOpacity(0);
        action(node,CCSequence::create(CCDelayTime::create(delay),CCFadeTo::create(duration,opacity),nullptr),PAINT);
    }
    class Entry final : public CCNode {
        WeakRef<CCNode> m_panel;
        void onEnter() override {
            CCNode::onEnter(); auto node = m_panel.lock();
            if (!node || mode() != rules::Mode::Full || !reserve(node.data())) return;
            auto rest = Rest::get(node.data()); rest->settle(node.data());
            node->setScaleX(rest->x*.96f); node->setScaleY(rest->y*.96f);
            action(node.data(),CCEaseSineOut::create(CCScaleTo::create(.20f,rest->x,rest->y)),TRANSFORM);
        }
        void onExit() override { if (auto node = m_panel.lock()) Rest::get(node.data())->settle(node.data()); CCNode::onExit(); }
    public:
        static Entry* create(CCNode* panel) {
            auto n = new Entry(); if (!n->init()) { delete n; return nullptr; }
            n->m_panel = panel; n->setID("mucho-motion-entry"); n->autorelease(); return n;
        }
    };
    class CounterValue final : public CCObject {
    public:
        int64_t value=0;
        static CounterValue* get(CCNode* node) {
            auto state=static_cast<CounterValue*>(node->getUserObject("mucho-counter-value"));
            if(!state) { state=new CounterValue(); node->setUserObject("mucho-counter-value",state); state->release(); }
            return state;
        }
    };
    class Count final : public CCActionInterval {
        int64_t m_from = 0, m_to = 0, m_last = -1;
        unsigned m_step = UINT_MAX;
        float m_width = 0, m_scale = 1;
        std::string m_prefix,m_suffix;
        bool m_compact = false;
        void update(float time) override {
            unsigned step = rules::step(time); if (step == m_step) return; m_step = step;
            auto value = rules::counter(m_from,m_to,static_cast<float>(step)/rules::counterSteps);
            if (value == m_last) return; m_last = value;
            auto label = static_cast<CCLabelBMFont*>(m_pTarget);
            CounterValue::get(label)->value=value;
            auto text = m_prefix+(m_compact ? clans::formattedCounter(static_cast<uint64_t>(value)) : std::to_string(value))+m_suffix;
            label->setString(text.c_str()); label->limitLabelWidth(m_width,m_scale,0.f);
        }
    public:
        static Count* create(int64_t from,int64_t to,float width,float scale,std::string prefix,std::string suffix,bool compact) {
            auto n = new Count(); if (!n->initWithDuration(.42f)) { delete n; return nullptr; }
            n->m_from=from; n->m_to=to; n->m_width=width; n->m_scale=scale;
            n->m_prefix=std::move(prefix); n->m_suffix=std::move(suffix); n->m_compact=compact; n->autorelease(); return n;
        }
    };
}
    rules::Mode mode() {
        return rules::mode(Mod::get()->getSettingValue<bool>("ui-animations"),
            Mod::get()->getSettingValue<bool>("reduced-motion"),PlayLayer::get() != nullptr);
    }
    void markButton(CCMenuItemSpriteExtra* item) { item->setUserObject("mucho-motion-button",CCBool::create(true)); }
    void panel(CCNode* node) {
        auto size=node->getContentSize(); auto window=CCDirector::get()->getWinSize();
        if(size.width>0 && size.height>0) {
            float fit=std::min({1.f,(window.width-40.f)/size.width,(window.height-40.f)/size.height});
            node->setScale(std::min(node->getScale(),std::max(.01f,fit)));
        }
        if (auto host = Entry::create(node)) node->addChild(host);
    }
    void reveal(CCNode* node,unsigned index) {
        if (!node) return;
        // Labels can have just been fitted to new text. Entry movement must
        // preserve that current scale instead of restoring an older caption.
        auto rest = Rest::get(node); rest->settle(node,false);
        if (!reserve(node)) return;
        float delay = rules::delay(index);
        if (mode() == rules::Mode::Full) {
            node->setPosition(rest->position+CCPoint{0.f,-4.f});
            action(node,CCSequence::create(CCDelayTime::create(delay),CCEaseSineOut::create(CCMoveTo::create(.18f,rest->position)),nullptr),TRANSFORM);
        }
        fade(node,.16f,delay);
    }
    void feedback(CCLabelBMFont* label) { reveal(label); }
    void appear(CCNode* node) { if(node && reserve(node)) fade(node,.20f); }
    void progress(CCNode* fill,float from,float to) {
        to = rules::ratio(to); from = rules::ratio(from);
        fill->stopActionByTag(TRANSFORM); fill->setScaleY(1.f);
        if (std::abs(from-to)<.001f || !reserve(fill)) { fill->setScaleX(to); return; }
        fill->setScaleX(from);
        action(fill,CCEaseSineOut::create(CCScaleTo::create(mode()==rules::Mode::Full?.36f:.16f,to,1.f)),TRANSFORM);
    }
    void number(CCLabelBMFont* label,int64_t from,int64_t to,float width,float scale,std::string prefix,std::string suffix,bool compact) {
        to = rules::counter(to,to,1.f); from = rules::counter(from,from,1.f);
        if(label->getActionByTag(NUMBER))from=numberValue(label,from);
        bool animate=from!=to && mode()==rules::Mode::Full && reserve(label);
        auto value=animate?from:to;
        CounterValue::get(label)->value=value;
        auto text = prefix+(compact ? clans::formattedCounter(static_cast<uint64_t>(value)) : std::to_string(value))+suffix;
        label->stopActionByTag(NUMBER); label->setString(text.c_str()); label->limitLabelWidth(width,scale,0.f);
        if (animate)
            action(label,Count::create(from,to,width,scale,std::move(prefix),std::move(suffix),compact),NUMBER);
    }
    int64_t numberValue(CCLabelBMFont* label,int64_t fallback) {
        if(!label)return fallback;
        auto state=static_cast<CounterValue*>(label->getUserObject("mucho-counter-value"));
        return state?state->value:fallback;
    }
    void reward(CCNode* parent,CCPoint at,int64_t xp) {
        if (!parent || xp<=0 || mode()==rules::Mode::Off) return;
        if (auto old=parent->getChildByID("mucho-reward-animation")) old->removeFromParent();
        auto group=CCNode::create(); group->setID("mucho-reward-animation"); parent->addChild(group,50);
        if (!reserve(group)) { group->removeFromParent(); return; }
        action(group,CCSequence::create(CCDelayTime::create(.8f),CCRemoveSelf::create(),nullptr),LOOP);
        auto label=CCLabelBMFont::create(("+"+std::to_string(xp)+" XP").c_str(),"goldFont.fnt");
        label->limitLabelWidth(160.f,.42f,0.f); label->setPosition(at); group->addChild(label);
        if (mode()==rules::Mode::Full) label->runAction(CCEaseSineOut::create(CCMoveBy::create(.6f,{0.f,14.f})));
        label->runAction(CCSequence::create(CCDelayTime::create(.35f),CCFadeOut::create(.3f),nullptr));
        for(unsigned i=0;i<rules::particles(mode());++i) {
            auto sprite=CCSprite::createWithSpriteFrameName("GJ_starsIcon_001.png"); if(!sprite) continue;
            float angle=static_cast<float>(i)*6.2831853f/rules::maxParticles;
            sprite->setScale(.16f); sprite->setPosition(at); group->addChild(sprite);
            sprite->runAction(CCSpawn::create(CCEaseSineOut::create(CCMoveBy::create(.55f,{std::cos(angle)*24.f,std::sin(angle)*12.f})),
                CCSequence::create(CCDelayTime::create(.18f),CCFadeOut::create(.35f),nullptr),nullptr));
        }
    }
    void loading(CCNode* parent,bool active,CCPoint at) {
        if (!parent) return;
        auto old=parent->getChildByID("mucho-loading-motion");
        if (!active || mode()==rules::Mode::Off) { if(old)old->removeFromParent(); return; }
        if(old)return;
        auto group=CCNode::create(); group->setID("mucho-loading-motion"); group->setPosition(at); parent->addChild(group,10);
        for(unsigned i=0;i<3;++i) {
            auto dot=CCLayerColor::create({105,220,255,190},3.f,3.f); dot->setPosition({static_cast<float>(i)*5.f,0.f}); group->addChild(dot);
            if(mode()==rules::Mode::Full && reserve(dot)) action(dot,CCRepeatForever::create(CCSequence::create(
                CCDelayTime::create(i*.12f),CCFadeTo::create(.35f,65),CCFadeTo::create(.35f,210),nullptr)),LOOP);
        }
    }
    bool dismiss(Popup* popup,CCNode* node,std::function<void()> done) {
        if(mode()!=rules::Mode::Full || !reserve(node))return false;
        popup->setTouchEnabled(false); popup->setKeypadEnabled(false);
        auto disable=[&](auto&& self,CCNode* n)->void {
            if(auto menu=typeinfo_cast<CCMenu*>(n))menu->setTouchEnabled(false);
            for(auto child:CCArrayExt<CCNode*>(n->getChildren()))self(self,child);
        };
        disable(disable,node);
        auto rest=Rest::get(node); rest->settle(node);
        action(node,CCSequence::create(CCEaseSineIn::create(CCScaleTo::create(.11f,rest->x*.97f,rest->y*.97f)),
            CallFuncExt::create(std::move(done)),nullptr),TRANSFORM);
        return true;
    }
    void press(CCMenuItemSpriteExtra* item,bool down) {
        item->stopActionByTag(PRESS);
        if(mode()!=rules::Mode::Full || !reserve(item)){item->setScale(item->m_baseScale);return;}
        action(item,CCEaseSineOut::create(CCScaleTo::create(down?.07f:.14f,item->m_baseScale*(down?.96f:1.f))),PRESS);
    }
}
class $modify(MuchoMotionButton,CCMenuItemSpriteExtra) {
    void selected() {
        if(!getUserObject("mucho-motion-button") || mucho::motion::mode()==mucho::motion::rules::Mode::Off){CCMenuItemSpriteExtra::selected();return;}
        CCMenuItemSprite::selected(); mucho::motion::press(this,true);
    }
    void unselected() {
        if(!getUserObject("mucho-motion-button") || mucho::motion::mode()==mucho::motion::rules::Mode::Off){CCMenuItemSpriteExtra::unselected();return;}
        CCMenuItemSprite::unselected(); mucho::motion::press(this,false);
    }
};
