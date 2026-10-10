#pragma once
#include <Geode/ui/Popup.hpp>
#include <Geode/utils/file.hpp>
#include <Geode/utils/base64.hpp>
#include "MuchoProfileArtwork.hpp"
#include "MuchoColorsPopup.hpp"
#include "MuchoUi.hpp"
#include <fstream>

using namespace geode::prelude;

namespace mucho::profile {
    void changed(matjson::Value const& player);
    inline std::string text(matjson::Value const& value, char const* key) { return value[key].asString().unwrapOr(""); }
    inline std::string version(matjson::Value const& player, std::string const& kind) { return text(player["media"][kind], "version"); }
    inline bool credential(int account, std::string& token) {
        auto manager = GJAccountManager::sharedState();
        if (!manager || manager->m_accountID != account || account <= 0) return false;
        token = std::string(manager->m_GJP2.c_str());
        return token.size() == 40 && std::all_of(token.begin(), token.end(), [](char c) {
            return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
        });
    }
    inline std::string error(web::WebResponse const& response) {
        if (response.code() == 404 || response.string().unwrapOr("") == "-1") return "Update MuchoCore on the server to enable profile images";
        if (response.code() == 429) return "Too many requests. Wait a moment and try again";
        if (response.string().unwrapOr("").size() > 16384) return "Invalid profile response";
        auto data = response.json();
        return data ? data.unwrap()["error"].asString().unwrapOr("Connection failed. Try again") : "Connection failed. Try again";
    }
}

class MuchoImageEditor final : public Popup {
    std::string m_origin, m_kind;
    int m_account = 0, m_x = 500, m_y = 500, m_zoom = 100, m_rotation = 0;
    bool m_busy = false, m_closed = false, m_selected = false;
    unsigned m_generation = 0;
    std::vector<uint8_t> m_bytes;
    mucho::profile::Artwork* m_preview = nullptr;
    CCLabelBMFont* m_status = nullptr;
    CCLabelBMFont* m_cropLabel = nullptr;
    std::vector<CCMenuItemSpriteExtra*> m_buttons, m_cropButtons;
    async::TaskHolder<file::PickResult> m_picker;
    async::TaskHolder<std::vector<uint8_t>> m_reader;
    async::TaskHolder<std::string> m_encoder;
    async::TaskHolder<web::WebResponse> m_request;
    std::function<void(matjson::Value const&)> m_saved;

    bool session() {
        std::string token;
        if (m_origin != mucho::profile::origin()) { status("Server changed. Close the editor and open it again"); return false; }
        if (!mucho::profile::credential(m_account, token)) { status("Account changed. Log in and reopen the editor"); return false; }
        return !m_closed;
    }
    void status(std::string value) {
        for (auto& c : value) if (static_cast<unsigned char>(c) < 32 || c == '<' || c == '>') c = ' ';
        if (value.size() > 160) value.resize(160);
        m_status->setString(value.c_str()); m_status->limitLabelWidth(330.f, .24f, 0.f);
    }
    void enabled() {
        for (auto b : m_buttons) b->setEnabled(!m_busy);
        for (auto b : m_cropButtons) b->setEnabled(!m_busy && m_selected);
    }
    CCMenuItemSpriteExtra* button(CCMenu* menu, char const* name, float x, float y, SEL_MenuHandler handler, float scale = .4f) {
        auto b = mucho::button(ButtonSprite::create(name), this, handler, scale);
        b->setPosition({x,y}); menu->addChild(b); m_buttons.push_back(b); return b;
    }
    void crop() {
        m_preview->crop(m_x, m_y, m_zoom, m_rotation);
        m_cropLabel->setString(("Zoom " + std::to_string(m_zoom) + "% | Rotate " + std::to_string(m_rotation)).c_str());
        status("Preview only - press Save to apply");
    }
    void onCrop(CCObject* object) {
        if (m_busy || !m_selected) return;
        switch (static_cast<CCMenuItemSpriteExtra*>(object)->getTag()) {
            case 0: m_x = std::max(0, m_x - 100); break;
            case 1: m_y = std::max(0, m_y - 100); break;
            case 2: m_y = std::min(1000, m_y + 100); break;
            case 3: m_x = std::min(1000, m_x + 100); break;
            case 4: m_zoom = std::max(100, m_zoom - 25); break;
            case 5: m_zoom = std::min(300, m_zoom + 25); break;
            case 6: m_rotation = (m_rotation + 90) % 360; break;
        }
        crop();
    }
    void onChoose(CCObject*) {
        if (m_busy || !session()) return;
        m_busy = true; enabled(); status("Choose a JPG or PNG image...");
        auto generation = ++m_generation;
        m_picker.spawn("Choose profile image", file::pick(file::PickMode::OpenFile, file::FilePickOptions{
            .filters = {{.description = "Profile images", .files = {"*.jpg", "*.jpeg", "*.png"}}}
        }), [this, generation](file::PickResult result) {
            if (m_closed || generation != m_generation) return;
            if (!session()) { m_busy = false; enabled(); return; }
            if (!result || !result.unwrap()) {
                m_busy = false; enabled(); status(result ? "Selection cancelled" : "Could not open the file picker"); return;
            }
            auto path = *result.unwrap(); status("Reading image...");
            m_reader.spawn("Read profile image", [path]() -> arc::Future<std::vector<uint8_t>> {
                std::error_code ec; auto size = std::filesystem::file_size(path, ec);
                if (ec || size < 24 || size > mucho::profile::MAX_UPLOAD_BYTES) co_return std::vector<uint8_t>{};
                std::ifstream stream(path, std::ios::binary);
                std::vector<uint8_t> bytes(static_cast<size_t>(size));
                if (!stream.read(reinterpret_cast<char*>(bytes.data()), static_cast<std::streamsize>(bytes.size()))) bytes.clear();
                co_return bytes;
            }, [this, generation](std::vector<uint8_t> bytes) {
                if (m_closed || generation != m_generation) return;
                m_busy = false; enabled(); if (!session()) return;
                auto dimensions = mucho::profile::imageSize(bytes);
                if (!dimensions) { status("Use a readable JPG/PNG up to 4 MB and 12 megapixels"); return; }
                m_bytes = std::move(bytes); m_selected = true;
                m_x = m_y = 500; m_zoom = 100; m_rotation = 0;
                m_preview->selected(m_bytes, dimensions->png); enabled(); crop();
            });
        });
    }
    void send(bool remove, std::string encoded = "") {
        if (!session()) { m_busy = false; enabled(); return; }
        std::string token; if (!mucho::profile::credential(m_account, token)) return;
        std::string body = "accountID=" + std::to_string(m_account) + "&gameVersion=22&gjp2=" + token + "&kind=" + m_kind;
        if (!remove) body += "&imageData=" + encoded + "&cropX=" + std::to_string(m_x) + "&cropY=" + std::to_string(m_y) +
            "&zoom=" + std::to_string(m_zoom) + "&rotation=" + std::to_string(m_rotation);
        auto generation = ++m_generation;
        m_busy = true; enabled(); status(remove ? "Removing..." : "Uploading image...");
        m_request.spawn("Save profile image", web::WebRequest().timeout(std::chrono::seconds(35)).followRedirects(false)
            .header("Content-Type", "application/x-www-form-urlencoded").bodyString(body)
            .post(m_origin + (remove ? "/api/profile/remove" : "/api/profile/upload")),
            [this, generation](web::WebResponse response) {
                if (m_closed || generation != m_generation) return;
                m_busy = false; enabled(); if (!session()) return;
                if (response.string().unwrapOr("").size() > 16384) { status("Invalid profile response"); return; }
                auto json = response.json();
                if (!response.ok() || !json || !json.unwrap()["ok"].asBool().unwrapOr(false)) { status(mucho::profile::error(response)); return; }
                auto player = json.unwrap()["data"];
                if (player["account_id"].asInt().unwrapOr(0) != m_account) { status("Invalid account response"); return; }
                m_selected = false; m_bytes.clear(); enabled();
                m_preview->stored(m_origin, m_account, m_kind, mucho::profile::version(player, m_kind));
                mucho::profile::changed(player);
                if (m_saved) m_saved(player);
                status("Saved! Players with MuchoClient can see your profile images");
            });
    }
    void onSave(CCObject*) {
        if (m_busy || !session()) return;
        if (!m_selected) { status("Choose an image first"); return; }
        if (!m_preview->ready()) { status(m_preview->loading() ? "Wait for the image preview to load" : "Image preview failed. Choose another JPG or PNG"); return; }
        m_busy = true; enabled(); status("Preparing upload..."); auto generation = ++m_generation;
        m_encoder.spawn("Encode profile image", [bytes = m_bytes]() -> arc::Future<std::string> {
            co_return mucho::clans::encoded(base64::encode(bytes, base64::Base64Variant::Normal));
        }, [this, generation](std::string encoded) {
            if (!m_closed && generation == m_generation) send(false, std::move(encoded));
        });
    }
    void onRemove(CCObject*) {
        if (m_busy || !session()) return;
        auto weak = WeakRef(this);
        createQuickPopup("Remove image", "Remove this profile image from your account?", "Cancel", "Remove",
            [weak](FLAlertLayer*, bool yes) { if (auto self = weak.lock(); yes && self && !self->m_busy && !self->m_closed) self->send(true); });
    }
protected:
    bool init(std::string url, int account, std::string kind, std::string savedVersion) {
        if (!Popup::init(360.f,300.f)) return false;
        m_origin = std::move(url); m_account = account; m_kind = std::move(kind);
        setTitle(m_kind == "avatar" ? "Profile Avatar" : "Profile Cover");
        m_preview = mucho::profile::Artwork::create(m_kind == "avatar" ? CCSize{104.f,104.f} : CCSize{312.f,104.f});
        m_preview->setPosition({m_kind == "avatar" ? 128.f : 24.f,143.f}); m_mainLayer->addChild(m_preview);
        auto hint = CCLabelBMFont::create("JPG / PNG | max 4 MB | max 12 MP", "bigFont.fnt");
        hint->setScale(.26f); hint->setPosition({180.f,132.f}); m_mainLayer->addChild(hint);
        auto menu = CCMenu::create(); menu->setPosition({0,0}); m_mainLayer->addChild(menu);
        char const* names[] = {"Left", "Up", "Down", "Right", "Zoom -", "Zoom +", "Rotate"};
        for (int i = 0; i < 7; ++i) {
            auto b = button(menu,names[i],i < 4 ? 54.f + i * 84.f : 67.f + (i - 4) * 113.f,
                i < 4 ? 107.f : 79.f,menu_selector(MuchoImageEditor::onCrop),.32f);
            b->setTag(i); m_cropButtons.push_back(b);
        }
        m_cropLabel = CCLabelBMFont::create("Crop and rotate after choosing an image", "bigFont.fnt");
        m_cropLabel->setScale(.24f); m_cropLabel->setPosition({180.f,55.f}); m_mainLayer->addChild(m_cropLabel);
        button(menu,"Choose",62.f,31.f,menu_selector(MuchoImageEditor::onChoose),.43f);
        button(menu,"Save",178.f,31.f,menu_selector(MuchoImageEditor::onSave),.5f);
        button(menu,"Remove",296.f,31.f,menu_selector(MuchoImageEditor::onRemove),.43f);
        m_status = CCLabelBMFont::create("", "bigFont.fnt"); m_status->setPosition({180.f,11.f}); m_mainLayer->addChild(m_status);
        enabled(); m_preview->stored(m_origin,m_account,m_kind,std::move(savedVersion)); status("Choose your own image, adjust the crop, then Save"); return true;
    }
    void onClose(CCObject* sender) override {
        m_closed = true; ++m_generation; m_picker.cancel(); m_reader.cancel(); m_encoder.cancel(); m_request.cancel();
        Popup::onClose(sender);
    }
public:
    static MuchoImageEditor* create(std::string url,int account,std::string kind,std::string savedVersion,
        std::function<void(matjson::Value const&)> saved) {
        if (!mucho::appearance::validOrigin(url) || account <= 0 || (kind != "avatar" && kind != "banner")) return nullptr;
        auto popup = new MuchoImageEditor(); popup->m_saved = std::move(saved);
        if (popup->init(std::move(url),account,std::move(kind),std::move(savedVersion))) { popup->autorelease(); return popup; }
        delete popup; return nullptr;
    }
};

class MuchoProfilePopup final : public Popup {
    std::string m_origin;
    int m_account = 0;
    bool m_closed = false, m_busy = false, m_loaded = false;
    unsigned m_generation = 0;
    matjson::Value m_player;
    mucho::profile::Artwork *m_avatar = nullptr, *m_banner = nullptr;
    CCLabelBMFont *m_name = nullptr, *m_caption = nullptr, *m_status = nullptr;
    std::vector<CCLabelBMFont*> m_stats;
    std::vector<CCMenuItemSpriteExtra*> m_edits;
    async::TaskHolder<web::WebResponse> m_request;

    void status(std::string value) {
        for (auto& c : value) if (static_cast<unsigned char>(c) < 32 || c == '<' || c == '>') c = ' ';
        if (value.size() > 160) value.resize(160);
        m_status->setString(value.c_str()); m_status->limitLabelWidth(380.f,.25f,0.f);
    }
    bool own() { auto manager = GJAccountManager::sharedState(); return manager && manager->m_accountID == m_account; }
    void apply(matjson::Value const& player) {
        ++m_generation; m_request.cancel(); m_busy = false;
        m_player = player; m_loaded = true;
        mucho::appearance::paint(m_name,mucho::profile::text(player,"username"),
            mucho::clans::normalizedTag(mucho::profile::text(player,"clan_tag")),mucho::profile::text(player,"nickname_color"),
            mucho::profile::text(player,"prefix_color"),288.f,.5f);
        m_avatar->stored(m_origin,m_account,"avatar",mucho::profile::version(player,"avatar"));
        m_banner->stored(m_origin,m_account,"banner",mucho::profile::version(player,"banner"));
        char const* fields[] = {"stars","moons","demons","diamonds","user_coins","creator_points"};
        for (size_t i = 0; i < m_stats.size(); ++i) {
            auto number = player["stats"][fields[i]].asInt().unwrapOr(0);
            m_stats[i]->setString(mucho::clans::formattedCounter(static_cast<uint64_t>(std::max(int64_t{0},number))).c_str());
            m_stats[i]->limitLabelWidth(54.f,.4f,0.f);
        }
        for (auto b : m_edits) { b->setVisible(own()); b->setEnabled(own()); }
        m_caption->setString(own() ? "Your profile" : "Player profile");
        mucho::appearance::remember(player); status("Profile images are visible to players with MuchoClient");
    }
    void load() {
        if (m_busy || m_closed || m_origin != mucho::profile::origin()) return;
        auto generation = ++m_generation; m_busy = true; status("Loading profile...");
        m_request.spawn("MuchoCore profile", web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false)
            .header("Content-Type","application/x-www-form-urlencoded").bodyString("accountID=" + std::to_string(m_account))
            .post(m_origin + "/api/profile/get"), [this, generation](web::WebResponse response) {
                if (m_closed || generation != m_generation) return;
                m_busy = false;
                if (m_origin != mucho::profile::origin()) { status("Server changed. Reopen the profile"); return; }
                if (response.string().unwrapOr("").size() > 16384) { status("Invalid profile response"); return; }
                auto json = response.json();
                if (!response.ok() || !json || !json.unwrap()["ok"].asBool().unwrapOr(false)) { status(mucho::profile::error(response)); return; }
                auto player = json.unwrap()["data"];
                if (player["account_id"].asInt().unwrapOr(0) != m_account) { status("Invalid account response"); return; }
                apply(player);
            });
    }
    void onReload(CCObject*) { load(); }
    void onEdit(CCObject* object) {
        if (!m_loaded || !own() || m_origin != mucho::profile::origin()) return;
        std::string kind = static_cast<CCMenuItemSpriteExtra*>(object)->getTag() == 0 ? "avatar" : "banner";
        auto weak = WeakRef(this);
        if (auto editor = MuchoImageEditor::create(m_origin,m_account,kind,mucho::profile::version(m_player,kind),
            [weak](matjson::Value const& player) { if (auto self = weak.lock(); self && !self->m_closed) self->apply(player); })) editor->show();
    }
    void onColors(CCObject*) {
        if (!m_loaded || !own()) return;
        auto weak = WeakRef(this);
        if (auto colors = MuchoColorsPopup::create(m_origin,[weak](matjson::Value const&) {
            if (auto self = weak.lock(); self && !self->m_closed) { ++self->m_generation; self->m_request.cancel(); self->m_busy = false; self->load(); }
        })) colors->show();
    }
protected:
    bool init(std::string url,int account) {
        if (!Popup::init(420.f,300.f)) return false;
        m_origin = std::move(url); m_account = account; setTitle("Mucho Profile v0.8.1");
        m_banner = mucho::profile::Artwork::create({376.f,125.3333f}); m_banner->setPosition({22.f,142.f}); m_mainLayer->addChild(m_banner);
        // A separate identity row keeps both images and the name aligned;
        // the square avatar never covers the banner or the statistics.
        auto identity = CCLayerColor::create({55,29,18,35},376,62);
        identity->setPosition({22,77}); m_mainLayer->addChild(identity);
        auto border = CCLayerColor::create({235,235,245,255},54,54);
        border->setPosition({28,81}); m_mainLayer->addChild(border,3);
        m_avatar = mucho::profile::Artwork::create({50,50}); m_avatar->setPosition({30,83}); m_mainLayer->addChild(m_avatar,4);
        m_name = CCLabelBMFont::create("Loading...","bigFont.fnt"); m_name->setAnchorPoint({0,.5f});
        m_name->setPosition({98,116}); m_mainLayer->addChild(m_name,4); m_name->limitLabelWidth(288.f,.5f,0.f);
        m_caption = CCLabelBMFont::create("","goldFont.fnt"); m_caption->setAnchorPoint({0,.5f});
        m_caption->setScale(.3f); m_caption->setPosition({98,96}); m_mainLayer->addChild(m_caption,4);
        auto statistics = CCLayerColor::create({55,29,18,35},376,35);
        statistics->setPosition({22,39}); m_mainLayer->addChild(statistics);
        char const* labels[] = {"Stars","Moons","Demons","Diamonds","User coins","Creator pts"};
        for (int i = 0; i < 6; ++i) {
            float x = 22.f + (i+.5f)*(376.f/6);
            auto title = CCLabelBMFont::create(labels[i],"goldFont.fnt"); title->limitLabelWidth(54.f,.26f,0.f);
            title->setPosition({x,66}); m_mainLayer->addChild(title,2);
            auto value = CCLabelBMFont::create("-","bigFont.fnt"); value->setScale(.4f); value->setPosition({x,50}); m_mainLayer->addChild(value,2); m_stats.push_back(value);
        }
        auto menu = CCMenu::create(); menu->setPosition({0,0}); m_mainLayer->addChild(menu,5);
        auto refresh = mucho::button(CCSprite::createWithSpriteFrameName("GJ_updateBtn_001.png"),this,menu_selector(MuchoProfilePopup::onReload),.48f);
        refresh->setPosition({387,283}); menu->addChild(refresh);
        char const* buttons[] = {"Avatar","Cover","Colors"};
        for (int i = 0; i < 3; ++i) {
            auto b = mucho::button(ButtonSprite::create(buttons[i]),this,i < 2 ? menu_selector(MuchoProfilePopup::onEdit) : menu_selector(MuchoProfilePopup::onColors),.43f);
            b->setTag(i); b->setPosition({22.f+(i+.5f)*(376.f/3),25.f}); b->setVisible(false); menu->addChild(b); m_edits.push_back(b);
        }
        m_status = CCLabelBMFont::create("","bigFont.fnt"); m_status->setPosition({210,9}); m_mainLayer->addChild(m_status);
        load(); return true;
    }
    void onClose(CCObject* sender) override { m_closed = true; ++m_generation; m_request.cancel(); Popup::onClose(sender); }
public:
    static MuchoProfilePopup* create(std::string url,int account) {
        if (!mucho::appearance::validOrigin(url) || account <= 0) return nullptr;
        auto popup = new MuchoProfilePopup();
        if (popup->init(std::move(url),account)) { popup->autorelease(); return popup; }
        delete popup; return nullptr;
    }
};
