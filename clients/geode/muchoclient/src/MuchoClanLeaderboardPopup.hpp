#pragma once

#include "MuchoClansPopup.hpp"
#include <array>

using namespace geode::prelude;

class MuchoClanLeaderboardPopup final : public geode::Popup {
    struct Metric { char const* key; char const* title; char const* icon; };
    static constexpr std::array<Metric, 7> METRICS = {{
        {"stars", "Stars", "GJ_bigStar_001.png"},
        {"demons", "Demons", "GJ_demonIcon_001.png"},
        {"moons", "Moons", "GJ_bigMoon_001.png"},
        {"diamonds", "Diamonds", "GJ_diamond_001.png"},
        {"user_coins", "User Coins", "GJ_silverCoin_001.png"},
        {"secret_coins", "Secret Coins", "GJ_goldCoin_001.png"},
        {"creator_points", "Creator Points", "GJ_creatorPoint_001.png"}
    }};
    static constexpr int PAGE_SIZE = 5;
    std::string m_origin, m_error;
    int m_metric = 0, m_page = 0, m_total = 0, m_retryMetric = 0, m_retryPage = 0;
    bool m_busy = false, m_closed = false, m_loaded = false, m_hasMore = false, m_renderQueued = false;
    unsigned m_generation = 0;
    std::vector<matjson::Value> m_clans;
    matjson::Value m_own;
    CCNode* m_body = nullptr;
    std::vector<CCMenuItemSpriteExtra*> m_buttons;
    async::TaskHolder<web::WebResponse> m_task;

    static int id(matjson::Value const& value, char const* key) {
        auto number = value[key].asInt().unwrapOr(0);
        return number > 0 && number <= INT_MAX ? static_cast<int>(number) : 0;
    }
    static uint64_t score(matjson::Value const& value) {
        auto number = value["score"].asInt().unwrapOr(0);
        return number > 0 ? static_cast<uint64_t>(number) : 0;
    }
    static std::string text(matjson::Value const& value, char const* key) {
        auto result = value[key].asString().unwrapOr("");
        for (auto& c : result) if (c == '<' || c == '>' || static_cast<unsigned char>(c) < 32) c = ' ';
        if (result.size() > 60) result.resize(60);
        return result;
    }
    CCLabelBMFont* label(CCNode* parent, std::string const& value, float x, float y,
                         float scale, float width, bool left = false) {
        auto node = CCLabelBMFont::create(value.c_str(), "bigFont.fnt");
        if (left) node->setAnchorPoint({0.f, .5f});
        node->setScale(scale);
        if (node->getScaledContentSize().width > width) node->setScale(width / node->getContentSize().width);
        node->setPosition({x, y}); parent->addChild(node);
        return node;
    }
    void addButton(std::string const& caption, float x, float y, SEL_MenuHandler handler,
                   int tag = 0, float maxWidth = 88.f, float scale = .43f) {
        auto sprite = ButtonSprite::create(caption.c_str());
        scale = std::min(scale, maxWidth / sprite->getContentSize().width);
        auto item = mucho::button(sprite, this, handler, scale);
        item->setTag(tag); item->setPosition({x, y}); item->setEnabled(!m_busy);
        auto menu = CCMenu::create(); menu->setPosition({0.f, 0.f}); menu->addChild(item);
        m_body->addChild(menu); m_buttons.push_back(item);
    }
    void redraw() {
        if (m_renderQueued || m_closed) return;
        m_renderQueued = true;
        geode::queueInMainThread([self = Ref<MuchoClanLeaderboardPopup>(this)] {
            self->m_renderQueued = false;
            if (!self->m_closed) self->render();
        });
    }
    void render() {
        m_body->removeAllChildrenWithCleanup(true); m_buttons.clear();
        for (size_t i = 0; i < METRICS.size(); ++i)
            addButton(METRICS[i].title, 57.f + (i % 4) * 102.f, 249.f - (i / 4) * 27.f,
                      menu_selector(MuchoClanLeaderboardPopup::onMetric), static_cast<int>(i));
        addButton("Refresh", 363.f, 222.f, menu_selector(MuchoClanLeaderboardPopup::onRefresh));
        label(m_body, std::string(METRICS[m_metric].title) + " - member totals", 210.f, 196.f, .3f, 380.f);
        if (m_clans.empty())
            label(m_body, m_busy ? "Loading clan rankings..." : !m_error.empty() ? m_error : "No clans yet",
                  210.f, 122.f, .37f, 375.f);
        for (size_t i = 0; i < m_clans.size(); ++i) {
            auto const& clan = m_clans[i];
            auto row = CCNode::create(); row->setContentSize({380.f, 27.f});
            bool own = clan["is_own"].asBool().unwrapOr(false);
            auto background = CCLayerColor::create(own ? ccc4(35, 115, 175, 230) :
                                                  i % 2 ? ccc4(102, 65, 38, 225) : ccc4(128, 82, 45, 225), 380.f, 27.f);
            row->addChild(background);
            auto rank = label(row, "#" + std::to_string(id(clan, "rank")), 25.f, 13.5f, .42f, 43.f);
            if (id(clan, "rank") <= 3) rank->setColor({255, 225, 85});
            label(row, text(clan, "name"), 53.f, 18.f, .32f, 193.f, true);
            label(row, "[" + text(clan, "tag") + "]  " + std::to_string(id(clan, "member_count")) + " members",
                  53.f, 6.f, .2f, 193.f, true);
            auto frame = METRICS[m_metric].icon;
            if (CCSpriteFrameCache::sharedSpriteFrameCache()->spriteFrameByName(frame)) {
                auto icon = CCSprite::createWithSpriteFrameName(frame);
                icon->setScale(16.f / std::max(icon->getContentSize().width, icon->getContentSize().height));
                icon->setPosition({266.f, 13.5f}); row->addChild(icon);
            }
            label(row, mucho::clans::formattedCounter(score(clan)), 325.f, 13.5f, .34f, 99.f);
            auto item = mucho::button(row, this, menu_selector(MuchoClanLeaderboardPopup::onClan));
            item->setTag(id(clan, "clan_id")); item->setEnabled(!m_busy);
            item->setPosition({210.f, 170.f - i * 29.f});
            auto menu = CCMenu::create(); menu->setPosition({0.f, 0.f}); menu->addChild(item);
            m_body->addChild(menu); m_buttons.push_back(item);
        }
        if (m_loaded && m_error.empty() && !m_busy) {
            if (m_own.isObject())
                addButton("#" + std::to_string(id(m_own, "rank")) + " [" + text(m_own, "tag") + "] " +
                          mucho::clans::formattedCounter(score(m_own)), 123.f, 21.f,
                          menu_selector(MuchoClanLeaderboardPopup::onOwn), 0, 224.f, .37f);
            else label(m_body, "Join a clan to get a rank", 123.f, 21.f, .23f, 218.f);
        } else label(m_body, m_busy ? "Loading..." : m_error, 123.f, 21.f, .23f, 218.f);
        int pages = std::max(1, (m_total - 1) / PAGE_SIZE + 1);
        label(m_body, std::to_string(m_page + 1) + "/" + std::to_string(pages), 333.f, 21.f, .27f, 60.f);
        if (m_page > 0) addButton("<", 287.f, 21.f, menu_selector(MuchoClanLeaderboardPopup::onPrev), 0, 27.f, .4f);
        if (m_hasMore) addButton(">", 379.f, 21.f, menu_selector(MuchoClanLeaderboardPopup::onNext), 0, 27.f, .4f);
    }
    void request(int metric, int page) {
        if (m_busy || m_closed || metric < 0 || metric >= static_cast<int>(METRICS.size()) || page < 0 || page > 20000) return;
        int account = GJAccountManager::sharedState()->m_accountID;
        m_retryMetric = metric; m_retryPage = page;
        auto generation = ++m_generation;
        m_busy = true; m_error.clear();
        for (auto button : m_buttons) button->setEnabled(false);
        redraw();
        m_task.spawn("MuchoCore clan rankings", web::WebRequest().timeout(std::chrono::seconds(12))
            .followRedirects(false).header("Content-Type", "application/x-www-form-urlencoded")
            .bodyString("metric=" + std::string(METRICS[metric].key) + "&offset=" + std::to_string(page * PAGE_SIZE) +
                        "&limit=" + std::to_string(PAGE_SIZE) + "&accountID=" + std::to_string(std::max(0, account)))
            .post(m_origin + "/api/clans/leaderboard"),
            [this, generation, metric, page, account](web::WebResponse response) {
                if (m_closed || generation != m_generation) return;
                m_busy = false;
                auto fail = [this](std::string const& message) { m_error = message; redraw(); };
                if (GJAccountManager::sharedState()->m_accountID != account) { fail("Account changed. Close and reopen rankings."); return; }
                auto raw = response.string().unwrapOr("");
                if (response.code() == 404 || raw == "-1") { fail("Update MuchoCore to enable clan rankings"); return; }
                if (response.code() == 429) { fail("Wait a moment and press Refresh"); return; }
                if (raw.size() > 262144) { fail("Clan response is too large"); return; }
                auto parsed = response.json();
                if (!response.ok() || !parsed || !parsed.unwrap()["ok"].asBool().unwrapOr(false)) {
                    fail("Cannot load rankings. Press Refresh"); return;
                }
                auto data = parsed.unwrap()["data"];
                if (text(data, "metric") != METRICS[metric].key || !data["clans"].isArray() ||
                    data["offset"].asInt().unwrapOr(-1) != page * PAGE_SIZE ||
                    data["clans"].size() > PAGE_SIZE || !data["has_more"].isBool()) {
                    fail("Invalid clan leaderboard response"); return;
                }
                std::vector<matjson::Value> clans;
                for (auto const& clan : data["clans"]) {
                    if (!clan.isObject() || !id(clan, "clan_id") || !id(clan, "rank") ||
                        !clan["score"].isNumber() || clan["score"].asInt().unwrapOr(-1) < 0) {
                        fail("Invalid clan leaderboard row"); return;
                    }
                    clans.push_back(clan);
                }
                m_metric = metric; m_page = page; m_clans = std::move(clans); m_own = data["own_clan"];
                m_hasMore = data["has_more"].asBool().unwrapOr(false); m_total = id(data, "total_clans");
                m_loaded = true; redraw();
            });
    }
    void open(int clanId) {
        if (m_busy || clanId <= 0) return;
        if (auto popup = MuchoClansPopup::create(m_origin, clanId)) popup->show();
    }
    void onMetric(CCObject* sender) { request(static_cast<CCNode*>(sender)->getTag(), 0); }
    void onRefresh(CCObject*) { request(m_error.empty() ? m_metric : m_retryMetric, m_error.empty() ? m_page : m_retryPage); }
    void onPrev(CCObject*) { if (m_page > 0) request(m_metric, m_page - 1); }
    void onNext(CCObject*) { if (m_hasMore) request(m_metric, m_page + 1); }
    void onClan(CCObject* sender) { open(static_cast<CCNode*>(sender)->getTag()); }
    void onOwn(CCObject*) { open(id(m_own, "clan_id")); }

protected:
    bool init(std::string origin) {
        if (!Popup::init(420.f, 306.f)) return false;
        m_origin = std::move(origin); setTitle("Clan Leaderboards v0.5.0");
        m_body = CCNode::create(); m_mainLayer->addChild(m_body);
        render(); request(0, 0); return true;
    }
    void onClose(CCObject* sender) override {
        m_closed = true; ++m_generation; m_task.cancel(); Popup::onClose(sender);
    }
    void onExit() override {
        m_closed = true; ++m_generation; m_task.cancel(); Popup::onExit();
    }
public:
    static MuchoClanLeaderboardPopup* create(std::string origin) {
        auto popup = new MuchoClanLeaderboardPopup();
        if (popup->init(std::move(origin))) { popup->autorelease(); return popup; }
        delete popup; return nullptr;
    }
};
