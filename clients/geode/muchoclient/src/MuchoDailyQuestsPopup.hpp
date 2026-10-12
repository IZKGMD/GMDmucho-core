#pragma once

#include <Geode/Geode.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoDailyQuestRules.hpp"
#include "MuchoClanRules.hpp"
#include "MuchoUi.hpp"
#include "MuchoLayoutRules.hpp"
#include <chrono>
#include <cstdio>
#include <set>
#include <map>
#include <string>
#include <vector>

using namespace geode::prelude;

class MuchoDailyQuestsPopup final : public geode::Popup {
    std::string m_origin, m_day, m_notice;
    int m_account = 0, m_remaining = 0;
    int m_lastSecond = -1;
    int64_t m_xp = 0, m_level = 1;
    bool m_busy = false, m_loaded = false, m_closed = false, m_renderQueued = false;
    unsigned m_generation = 0;
    std::chrono::steady_clock::time_point m_received;
    std::vector<matjson::Value> m_quests;
    std::vector<CCMenuItemSpriteExtra*> m_claimButtons;
    CCMenuItemSpriteExtra* m_refresh = nullptr;
    CCNode* m_body = nullptr;
    CCLabelBMFont* m_reset = nullptr;
    CCLabelBMFont* m_xpLabel = nullptr;
    int64_t m_displayXp = 0, m_rewardXp = 0;
    std::string m_displayDay, m_rewardQuest;
    std::map<std::string,WeakRef<CCNode>> m_fills;
    std::map<std::string,float> m_ratios;
    async::TaskHolder<web::WebResponse> m_task;

    static std::string text(matjson::Value const& value, char const* key) {
        return value[key].asString().unwrapOr("");
    }
    static int64_t amount(matjson::Value const& value, char const* key) {
        return value[key].asInt().unwrapOr(-1);
    }
    static std::string clean(std::string value, size_t limit = 100) {
        for (auto& c : value) if (c == '<' || c == '>' || static_cast<unsigned char>(c) < 32) c = ' ';
        if (value.size() > limit) value.resize(limit);
        return value;
    }
    bool sameSession() const {
        auto* account = GJAccountManager::sharedState();
        auto origin = Mod::get()->getSettingValue<std::string>("server-url");
        while (!origin.empty() && origin.back() == '/') origin.pop_back();
        return account && account->m_accountID == m_account && origin == m_origin;
    }
    int secondsLeft() const {
        auto elapsed = std::chrono::duration_cast<std::chrono::seconds>(
            std::chrono::steady_clock::now() - m_received).count();
        return mucho::quests::remaining(m_remaining, elapsed);
    }
    CCLabelBMFont* label(std::string const& value, float x, float y, float scale, float width) {
        auto node = CCLabelBMFont::create(clean(value,400).c_str(),"bigFont.fnt");
        node->setScale(scale);
        if (node->getScaledContentSize().width > width) node->setScale(width/node->getContentSize().width);
        node->setPosition({x,y}); m_body->addChild(node); return node;
    }
    CCMenuItemSpriteExtra* button(std::string const& value, float x, float y, SEL_MenuHandler handler, int tag = 0) {
        auto sprite = ButtonSprite::create(value.c_str());
        auto item = mucho::button(sprite,this,handler,std::min(.46f,100.f/sprite->getContentSize().width));
        item->setPosition({x,y}); item->setTag(tag);
        auto menu = CCMenu::create(); menu->setPosition({0.f,0.f}); menu->addChild(item); m_body->addChild(menu);
        return item;
    }
    void render() {
        using L = mucho::layout::DailyQuests;
        m_displayXp=mucho::motion::numberValue(m_xpLabel,m_displayXp); m_xpLabel=nullptr;
        if(m_displayDay==m_day) {
            for(auto const& [id,weak]:m_fills)if(auto fill=weak.lock())m_ratios[id]=fill->getScaleX();
        } else m_ratios.clear();
        m_fills.clear(); m_displayDay=m_day;
        m_reset = nullptr; m_refresh = nullptr; m_claimButtons.clear(); m_body->removeAllChildren();
        if(m_loaded) {
            m_xpLabel=label("",130.f,L::xpY,.38f,230.f);
            mucho::motion::number(m_xpLabel,m_displayXp,m_xp,230.f,.38f,"Quest XP: ");
            label("Level "+std::to_string(m_level),330.f,L::xpY,.38f,125.f);
        } else label("Quest XP syncs with your account",210.f,L::xpY,.40f,385.f);
        m_reset = label(m_loaded ? "" : "Three daily goals | Refresh to connect",210.f,L::resetY,.29f,390.f);
        if (m_loaded) {
            for (size_t i = 0; i < m_quests.size(); ++i) {
                auto const& quest = m_quests[i];
                float y = L::cardBottom(static_cast<unsigned>(i));
                auto card = CCLayerColor::create({0,0,0,85},L::cardWidth,L::cardHeight); card->setPosition({L::cardLeft,y}); m_body->addChild(card);
                mucho::motion::reveal(card,static_cast<unsigned>(i));
                auto title=label(text(quest,"title"),L::textX,y+40.f,.34f,L::textWidth);
                mucho::motion::reveal(title,static_cast<unsigned>(i));
                auto target = amount(quest,"target"), progress = amount(quest,"progress");
                label(std::to_string(progress) + "/" + std::to_string(target) + "  |  +" +
                    std::to_string(amount(quest,"reward_xp")) + " XP",L::textX,y+23.f,.28f,L::textWidth);
                auto track = CCLayerColor::create({20,25,40,255},242.f,5.f); track->setPosition({26.f,y+9.f}); m_body->addChild(track);
                auto fill = CCLayerColor::create({105,225,145,255},242.f,5.f);
                fill->ignoreAnchorPointForPosition(false); fill->setAnchorPoint({0.f,0.f});
                fill->setPosition({26.f,y+9.f}); m_body->addChild(fill);
                auto id=text(quest,"id");
                float to=static_cast<float>(mucho::quests::progressRatio(progress,target));
                mucho::motion::progress(fill,m_ratios.contains(id)?m_ratios[id]:0.f,to); m_fills[id]=fill;
                bool claimed = quest["claimed"].asBool().unwrapOr(false), ready = quest["claimable"].asBool().unwrapOr(false);
                auto claim = button(claimed ? "Claimed" : ready ? "Claim" : "In progress",L::buttonX,y+26.f,
                    menu_selector(MuchoDailyQuestsPopup::onClaim),static_cast<int>(i));
                claim->setEnabled(!m_busy && ready && secondsLeft() > 0); m_claimButtons.push_back(claim);
                mucho::motion::reveal(claim,static_cast<unsigned>(i));
                if(m_rewardXp>0 && m_rewardQuest==id)mucho::motion::reward(m_body,{185.f,y+29.f},m_rewardXp);
            }
        } else {
            label(m_busy ? "Loading Daily Quests..." : "Daily Quests connects to your GDPS account",210.f,153.f,.38f,385.f);
        }
        m_refresh = button(m_busy ? "Loading..." : "Refresh",210.f,L::refreshY,menu_selector(MuchoDailyQuestsPopup::onRefresh));
        m_refresh->setEnabled(!m_busy);
        mucho::motion::loading(m_body,m_busy,{386.f,L::resetY-1.f});
        label(m_notice.empty() ? "Progress follows accepted server profile syncs" : m_notice,210.f,L::noticeY,.23f,392.f);
        m_rewardXp=0; m_rewardQuest.clear();
        m_lastSecond = -1; refreshTimer(0.f);
    }
    void redraw() {
        if (m_closed || m_renderQueued) return;
        m_renderQueued = true;
        Loader::get()->queueInMainThread([weak = WeakRef<MuchoDailyQuestsPopup>(this)] {
            auto self = weak.lock();
            if (!self || self->m_closed) return;
            self->m_renderQueued = false; self->render();
        });
    }
    void fail(std::string const& reason) {
        m_busy = false; m_loaded = false; m_quests.clear(); m_notice = clean(reason,180); redraw();
    }
    bool accept(matjson::Value const& data) {
        if (!data.isObject() || amount(data,"schema_version") != 1 || amount(data,"account_id") != m_account ||
            !mucho::quests::validDay(text(data,"day")) || amount(data,"xp") < 0 || amount(data,"xp") > 1000000000000LL ||
            amount(data,"quest_level") < 1 || amount(data,"quest_level") > 1000000000LL ||
            amount(data,"remaining_seconds") < 0 || amount(data,"remaining_seconds") > 86400 || !data["quests"].isArray()) return false;
        std::vector<matjson::Value> quests; std::set<std::string> ids;
        for (auto const& quest : data["quests"]) {
            if (!quest.isObject()) return false;
            auto id = text(quest,"id");
            if (quests.size() >= 3 || !quest.isObject() || !ids.insert(id).second ||
                !quest["claimed"].isBool() || !quest["claimable"].isBool() || text(quest,"title").empty() ||
                !mucho::quests::validQuest(id,amount(quest,"target"),amount(quest,"progress"),amount(quest,"reward_xp"),
                    quest["claimed"].asBool().unwrapOr(false),quest["claimable"].asBool().unwrapOr(false))) return false;
            quests.push_back(quest);
        }
        if (quests.size() != 3) return false;
        m_quests = std::move(quests); m_day = text(data,"day"); m_xp = amount(data,"xp"); m_level = amount(data,"quest_level");
        m_remaining = static_cast<int>(amount(data,"remaining_seconds")); m_received = std::chrono::steady_clock::now();
        m_loaded = true; return true;
    }
    void request(bool claim, std::string const& questId = "") {
        if (m_busy || m_closed) return;
        if (!sameSession()) { fail("Account or server changed. Reopen Daily Quests."); return; }
        auto* account = GJAccountManager::sharedState();
        std::string credential = account ? std::string(account->m_GJP2.c_str()) : "";
        if (m_account <= 0 || credential.size() != 40 || !std::all_of(credential.begin(),credential.end(),[](unsigned char c) {
            return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
        })) { fail("Log into your GDPS account, then reopen Daily Quests."); return; }
        std::string body = "accountID=" + std::to_string(m_account) + "&gameVersion=22&gjp2=" + mucho::clans::encoded(credential);
        if (claim) body += "&day=" + mucho::clans::encoded(m_day) + "&questID=" + mucho::clans::encoded(questId);
        m_busy = true; m_notice = claim ? "Collecting reward..." : "Connecting...";
        for (auto* button : m_claimButtons) button->setEnabled(false);
        if (m_refresh) m_refresh->setEnabled(false);
        redraw();
        auto generation = ++m_generation;
        m_task.spawn("MuchoCore Daily Quests",web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false)
            .header("Content-Type","application/x-www-form-urlencoded").bodyString(body)
            .post(m_origin + (claim ? "/extensions/daily-quests/claim" : "/extensions/daily-quests")),
            [this,generation,claim,questId](web::WebResponse response) {
                if (m_closed || generation != m_generation) return;
                if (!sameSession()) { fail("Account or server changed. Reopen Daily Quests."); return; }
                m_busy = false;
                auto raw = response.string().unwrapOr("");
                if (raw.size() > 32768) { fail("Daily Quest response is too large."); return; }
                if (response.code() == 404 || raw == "-1") { fail("Daily Quests needs the server update. Press Refresh after updating MuchoCore."); return; }
                if (response.code() == 429) { fail("Too many requests. Wait and press Refresh."); return; }
                auto parsed = response.json();
                if (!parsed) { fail("Server did not return Daily Quest data. Press Refresh to retry."); return; }
                auto root = parsed.unwrap();
                if (!root.isObject()) { fail("Invalid Daily Quest response."); return; }
                if (!response.ok() || !root["ok"].asBool().unwrapOr(false)) {
                    auto error = text(root,"error"); fail(error.empty() ? "Daily Quest request failed. Press Refresh to retry." : error); return;
                }
                auto previousXp=m_xp;
                if (!accept(root["data"])) { fail("Unsupported Daily Quest data. Update MuchoCore and MuchoClient."); return; }
                if(claim && root["data"]["claimed_now"].asBool().unwrapOr(false) && m_xp>previousXp) {
                    m_rewardXp=m_xp-previousXp; m_rewardQuest=questId;
                }
                m_notice = claim ? (root["data"]["claimed_now"].asBool().unwrapOr(false) ? "Reward collected!" : "Reward was already collected.") : "Synced with MuchoCore";
                redraw();
            });
    }
    void onRefresh(CCObject*) { request(false); }
    void onClaim(CCObject* sender) {
        auto index = static_cast<CCNode*>(sender)->getTag();
        if (m_busy || !m_loaded || secondsLeft() == 0 || index < 0 || static_cast<size_t>(index) >= m_quests.size()) return;
        auto const& quest = m_quests[static_cast<size_t>(index)];
        if (quest["claimable"].asBool().unwrapOr(false)) request(true,text(quest,"id"));
    }
protected:
    bool init(std::string origin) {
        using L = mucho::layout::DailyQuests;
        if (!Popup::init(L::width,L::height)) return false;
        auto window = CCDirector::get()->getWinSize();
        m_mainLayer->setScale(mucho::layout::popupScale(window.width,window.height,L::width,L::height));
        m_mainLayer->setID("daily-quests-panel");
        m_origin = std::move(origin); auto* account = GJAccountManager::sharedState(); m_account = account ? account->m_accountID : 0;
        setTitle("Mucho | Daily Quests"); m_body = CCNode::create(); m_mainLayer->addChild(m_body);
        render(); schedule(schedule_selector(MuchoDailyQuestsPopup::refreshTimer),1.f); request(false); mucho::motion::panel(m_mainLayer); return true;
    }
    void refreshTimer(float) {
        if (!m_loaded || !m_reset || m_closed) return;
        int left = secondsLeft(); char timer[64];
        if (left == m_lastSecond) return;
        m_lastSecond = left;
        std::snprintf(timer,sizeof(timer),"UTC reset in %02d:%02d:%02d",left/3600,(left/60)%60,left%60);
        m_reset->setString(left > 0 ? timer : "New quest day. Press Refresh.");
        if (left == 0) for (auto* button : m_claimButtons) button->setEnabled(false);
    }
    void onClose(CCObject* sender) override {
        if(m_closed)return; m_closed = true; ++m_generation; m_task.cancel();
        auto weak=WeakRef(this);
        if(!mucho::motion::dismiss(this,m_mainLayer,[weak] { if(auto self=weak.lock())self->Popup::onClose(nullptr); }))Popup::onClose(sender);
    }
    void onExit() override { m_closed = true; ++m_generation; m_task.cancel(); Popup::onExit(); }
public:
    static MuchoDailyQuestsPopup* create(std::string origin) {
        auto* popup = new MuchoDailyQuestsPopup();
        if (popup->init(std::move(origin))) { popup->autorelease(); return popup; }
        delete popup; return nullptr;
    }
};

