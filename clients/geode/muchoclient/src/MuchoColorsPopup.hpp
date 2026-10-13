#pragma once
#include "MuchoEmoji.hpp"

#include <Geode/ui/Popup.hpp>
#include <Geode/ui/TextInput.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoAppearance.hpp"
#include "MuchoUi.hpp"
#include <array>
#include <chrono>
#include <functional>

using namespace geode::prelude;

class MuchoColorsPopup final : public Popup {
    static constexpr std::array<char const*, 12> PALETTE = {
        "FFFFFF", "FFD166", "FF9F43", "FF6B6B", "FF78CB", "BD93F9",
        "829BFF", "66CCFF", "56E5DE", "64E572", "CBF078", "B8C4D6"
    };
    std::string m_origin, m_name, m_tag, m_nickname, m_prefix = "FFD166", m_originalPrefix;
    bool m_owner = false, m_loaded = false, m_busy = false, m_closed = false, m_editPrefix = false;
    int m_account = 0;
    unsigned m_generation = 0;
    TextInput* m_hex = nullptr;
    CCLabelBMFont *m_preview = nullptr, *m_status = nullptr, *m_target = nullptr;
    CCMenuItemSpriteExtra* m_prefixButton = nullptr;
    CCMenu* m_controls = nullptr;
    std::vector<CCMenuItemSpriteExtra*> m_buttons;
    async::TaskHolder<web::WebResponse> m_task;
    std::function<void(matjson::Value const&)> m_onSaved;

    CCLabelBMFont* label(char const* text, float y, float scale) {
        auto node = CCLabelBMFont::create(text, "bigFont.fnt");
        node->setScale(scale); node->setPosition({180.f, y}); m_mainLayer->addChild(node); return node;
    }
    CCMenuItemSpriteExtra* button(char const* text, float x, float y, SEL_MenuHandler handler, float scale = .5f) {
        auto item = mucho::button(ButtonSprite::create(text), this, handler, scale);
        item->setPosition({x, y}); m_controls->addChild(item); m_buttons.push_back(item); return item;
    }
    void enabled() {
        for (auto item : m_buttons) item->setEnabled(!m_busy && m_loaded);
        if (m_prefixButton) m_prefixButton->setEnabled(!m_busy && m_loaded && m_owner);
        m_hex->setEnabled(!m_busy && m_loaded);
    }
    void status(std::string text) {
        for (auto& c : text) if (static_cast<unsigned char>(c) < 32 || c == '<' || c == '>') c = ' ';
        if (text.size() > 180) text = mucho::emoji::shortenText(text,180);
        m_status->setString(text.c_str()); m_status->limitLabelWidth(320.f, .27f, 0.f);
        mucho::motion::feedback(m_status);
        mucho::motion::loading(m_mainLayer,m_busy,{342.f,12.f});
    }
    void preview() {
        mucho::appearance::paint(m_preview, m_name.empty() ? "YourName" : m_name, m_tag,
            m_nickname, m_prefix, 300.f, .6f);
        m_target->setString(m_editPrefix ? "Clan prefix color" : "Your nickname color");
    }
    void select(bool prefix) {
        if (!m_loaded || m_busy || (prefix && !m_owner)) return;
        m_editPrefix = prefix;
        m_hex->setString(m_editPrefix ? m_prefix : m_nickname, false);
        status(m_editPrefix ? "This color applies to every clan member" : "Empty HEX restores the normal nickname color");
        preview();
        mucho::motion::feedback(m_preview);
    }
    void onNickname(CCObject*) { select(false); }
    void onPrefix(CCObject*) { select(true); }
    void onPalette(CCObject* sender) {
        if (!m_loaded || m_busy) return;
        int index = static_cast<CCMenuItemSpriteExtra*>(sender)->getTag();
        if (index < 0 || index >= static_cast<int>(PALETTE.size())) return;
        auto hex = std::string(PALETTE[static_cast<size_t>(index)]);
        (m_editPrefix ? m_prefix : m_nickname) = hex;
        m_hex->setString(hex, false); preview(); status("Preview only - press Save to apply");
        mucho::motion::feedback(m_preview);
    }
    void onDefault(CCObject*) {
        if (!m_loaded || m_busy) return;
        (m_editPrefix ? m_prefix : m_nickname) = m_editPrefix ? "FFD166" : "";
        m_hex->setString(m_editPrefix ? m_prefix : m_nickname, false); preview();
        status("Preview only - press Save to apply");
    }
    void onRetry(CCObject*) { if (!m_busy) request(false); }
    void onSave(CCObject*) {
        if (!m_loaded || m_busy) return;
        if ((!m_nickname.empty() && mucho::appearance::normalizedColor(m_nickname).empty()) ||
            (m_owner && mucho::appearance::normalizedColor(m_prefix).empty())) {
            status("Enter six HEX digits, for example 66CCFF"); return;
        }
        request(true);
    }
    void request(bool save) {
        if (m_busy || m_closed || !mucho::appearance::validOrigin(m_origin)) return;
        auto origin = Mod::get()->getSettingValue<std::string>("server-url");
        while (!origin.empty() && origin.back() == '/') origin.pop_back();
        if (origin != m_origin) { status("Server changed. Close Colors and open it again"); return; }
        auto account = GJAccountManager::sharedState();
        std::string credential = account ? std::string(account->m_GJP2.c_str()) : "";
        if (!account || account->m_accountID <= 0 || (m_account > 0 && m_account != account->m_accountID) ||
            credential.size() != 40 || !std::all_of(credential.begin(), credential.end(), [](unsigned char c) {
                return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
            })) { status("Log into your GDPS account, then press Retry"); return; }
        m_account = account->m_accountID;
        std::string body = "accountID=" + std::to_string(m_account) + "&gameVersion=22&gjp2=" + mucho::clans::encoded(credential);
        if (save) {
            body += "&nicknameColor=" + mucho::clans::encoded(mucho::appearance::normalizedColor(m_nickname));
            if (m_owner && m_prefix != m_originalPrefix) body += "&prefixColor=" + mucho::clans::encoded(mucho::appearance::normalizedColor(m_prefix));
        }
        auto generation = ++m_generation;
        m_busy = true; enabled(); status(save ? "Saving..." : "Loading colors...");
        m_task.spawn("MuchoCore colors", web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false)
            .header("Content-Type", "application/x-www-form-urlencoded").bodyString(body)
            .post(m_origin + (save ? "/api/appearance/save" : "/api/appearance/my")),
            [this, save, generation](web::WebResponse response) {
                if (m_closed || generation != m_generation) return;
                m_busy = false; enabled();
                auto account = GJAccountManager::sharedState();
                auto origin = Mod::get()->getSettingValue<std::string>("server-url");
                while (!origin.empty() && origin.back() == '/') origin.pop_back();
                if (origin != m_origin) { status("Server changed. Close Colors and open it again"); return; }
                if (!account || account->m_accountID != m_account) {
                    m_loaded = false; enabled(); status("Account changed. Close Colors and open it again"); return;
                }
                if (response.code() == 404 || response.string().unwrapOr("") == "-1") {
                    status("Update MuchoCore on the server to enable colors"); return;
                }
                if (response.code() == 429) { status("Too many requests. Wait a moment and retry"); return; }
                if (response.string().unwrapOr("").size() > 16384) { status("Invalid color response"); return; }
                auto json = response.json();
                if (!json || !response.ok() || !json.unwrap()["ok"].asBool().unwrapOr(false)) {
                    status(json ? json.unwrap()["error"].asString().unwrapOr("Connection failed. Press Retry") : "Connection failed. Press Retry"); return;
                }
                auto player = json.unwrap()["data"];
                if (!player.isObject() || player["account_id"].asInt().unwrapOr(0) != m_account) { status("Invalid player response"); return; }
                m_name = player["username"].asString().unwrapOr("YourName");
                m_tag = mucho::clans::normalizedTag(player["clan_tag"].asString().unwrapOr(""));
                m_nickname = player["nickname_color"].asString().unwrapOr("");
                m_prefix = player["prefix_color"].asString().unwrapOr("FFD166");
                m_originalPrefix = m_prefix;
                m_owner = player["can_edit_prefix"].asBool().unwrapOr(false);
                m_loaded = true; if (!m_owner) m_editPrefix = false;
                enabled(); select(m_editPrefix);
                if (save) mucho::appearance::invalidate();
                mucho::appearance::remember(player);
                if (save && m_onSaved) m_onSaved(player);
                status(save ? "Saved! Other players see the colors with MuchoClient" : m_owner ? "Choose nickname or clan prefix, then a color" : "You can change your nickname; the owner sets the clan color");
            });
    }
protected:
    bool init(std::string origin) {
        if (!Popup::init(360.f, 300.f)) return false;
        m_origin = std::move(origin); setTitle("Colors v0.8.4");
        m_controls = CCMenu::create(); m_controls->setPosition({0.f, 0.f}); m_mainLayer->addChild(m_controls);
        m_preview = label("YourName", 243.f, .6f);
        m_preview->setID("mucho-color-preview");
        button("Nickname", 95.f, 206.f, menu_selector(MuchoColorsPopup::onNickname));
        m_prefixButton = button("Clan prefix", 265.f, 206.f, menu_selector(MuchoColorsPopup::onPrefix));
        m_target = label("Your nickname color", 177.f, .33f);
        for (size_t i = 0; i < PALETTE.size(); ++i) {
            auto sprite = CCScale9Sprite::create("square02_small.png");
            sprite->setContentSize({26.f, 26.f}); sprite->setColor(mucho::appearance::rgb(PALETTE[i]));
            auto item = mucho::button(sprite, this, menu_selector(MuchoColorsPopup::onPalette));
            item->setTag(static_cast<int>(i)); item->setPosition({60.f + (i % 6) * 48.f, 147.f - (i / 6) * 33.f});
            item->m_scaleMultiplier = 1.08f; m_controls->addChild(item); m_buttons.push_back(item);
            mucho::motion::reveal(item,static_cast<unsigned>(i%6));
        }
        m_hex = TextInput::create(176.f, "HEX: 66CCFF", "bigFont.fnt");
        m_hex->setMaxCharCount(7); m_hex->setFilter("0123456789abcdefABCDEF#");
        m_hex->setPosition({115.f, 77.f}); m_mainLayer->addChild(m_hex);
        m_hex->setCallback([this](std::string const& text) {
            (m_editPrefix ? m_prefix : m_nickname) = text;
            preview(); status("Preview only - press Save to apply");
        });
        button("Default", 278.f, 77.f, menu_selector(MuchoColorsPopup::onDefault), .43f);
        button("Save", 260.f, 39.f, menu_selector(MuchoColorsPopup::onSave));
        auto retry = button("Retry", 100.f, 39.f, menu_selector(MuchoColorsPopup::onRetry));
        // Retry stays available before a successful load.
        m_buttons.erase(std::remove(m_buttons.begin(), m_buttons.end(), retry), m_buttons.end());
        m_status = label("", 14.f, .27f); enabled(); request(false); mucho::motion::panel(m_mainLayer); return true;
    }
    void onClose(CCObject* sender) override {
        if(m_closed)return; m_closed = true; ++m_generation; m_task.cancel();
        auto weak=WeakRef(this);
        if(!mucho::motion::dismiss(this,m_mainLayer,[weak] { if(auto self=weak.lock())self->Popup::onClose(nullptr); }))Popup::onClose(sender);
    }
public:
    static MuchoColorsPopup* create(std::string origin, std::function<void(matjson::Value const&)> onSaved = {}) {
        if (!mucho::appearance::validOrigin(origin)) return nullptr;
        auto popup = new MuchoColorsPopup();
        popup->m_onSaved = std::move(onSaved);
        if (popup->init(std::move(origin))) { popup->autorelease(); return popup; }
        delete popup; return nullptr;
    }
};


