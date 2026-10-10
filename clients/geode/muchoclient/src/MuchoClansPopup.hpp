#pragma once

#include <Geode/Geode.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/ui/TextInput.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>

#include <algorithm>
#include <cctype>
#include <chrono>
#include <cstdio>
#include <string>
#include <vector>

using namespace geode::prelude;

// Never store credentials in Geode settings, save data, or logs.
// Only the game's current GJP2 is sent to the user's configured HTTPS MuchoCore host.
class MuchoClansPopup final : public geode::Popup {
    enum class View { My, Search, Detail, Create, Invites, LeaveConfirm, InviteMember };
    enum class Action { My, Search, Detail, Create, Join, Leave, Invites, Accept, Decline, Invite };

    std::string m_origin;
    View m_view = View::My;
    async::TaskHolder<web::WebResponse> m_task;
    cocos2d::CCNode* m_body = nullptr;
    geode::TextInput* m_queryInput = nullptr;
    geode::TextInput* m_nameInput = nullptr;
    geode::TextInput* m_tagInput = nullptr;
    geode::TextInput* m_descriptionInput = nullptr;
    geode::TextInput* m_accountInput = nullptr;
    std::string m_query;
    matjson::Value m_my;
    matjson::Value m_selected;
    std::vector<matjson::Value> m_results;
    std::vector<matjson::Value> m_invitations;
    int m_page = 0;

    static std::string clean(std::string str, size_t limit = 54) {
        for (auto& ch : str) {
            unsigned char c = static_cast<unsigned char>(ch);
            if (ch == '<' || ch == '>' || c < 32) ch = ' ';
        }
        if (str.size() > limit) str.resize(limit);
        return str;
    }

    static std::string stringField(matjson::Value const& object, char const* key) {
        return object[key].asString().unwrapOr("");
    }

    static int intField(matjson::Value const& object, char const* key) {
        return object[key].asInt().unwrapOr(0);
    }

    static std::string escaped(std::string const& value) {
        static char const* hex = "0123456789ABCDEF";
        std::string out;
        for (unsigned char c : value) {
            if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                (c >= '0' && c <= '9') || c == '-' || c == '_' ||
                c == '.' || c == '~') {
                out.push_back(static_cast<char>(c));
            } else {
                out.push_back('%');
                out.push_back(hex[c >> 4]);
                out.push_back(hex[c & 0xf]);
            }
        }
        return out;
    }

    void info(std::string const& title, std::string const& message) {
        FLAlertLayer::create(clean(title, 50).c_str(), clean(message, 250).c_str(), "OK")->show();
    }

    void label(std::string const& text, float x, float y, float scale = .39f) {
        auto node = CCLabelBMFont::create(clean(text, 94).c_str(), "bigFont.fnt");
        node->setScale(scale);
        node->setPosition({x, y});
        m_body->addChild(node);
    }

    void button(std::string const& title, float x, float y,
                SEL_MenuHandler handler, int tag = 0, float scale = .65f) {
        auto btn = CCMenuItemSpriteExtra::create(
            ButtonSprite::create(clean(title, 26).c_str()),
            this, handler
        );
        btn->setTag(tag);
        btn->setScale(scale);
        btn->setPosition({x, y});
        auto menu = CCMenu::create();
        menu->setPosition({0.f, 0.f});
        menu->addChild(btn);
        m_body->addChild(menu);
    }

    geode::TextInput* input(std::string const& placeholder,
                            float x, float y, float width, size_t maxChars) {
        auto field = geode::TextInput::create(width, placeholder, "bigFont.fnt");
        field->setMaxCharCount(maxChars);
        field->setPosition({x, y});
        m_body->addChild(field);
        return field;
    }

    void nav() {
        button("My Clan", 82.f, 228.f, menu_selector(MuchoClansPopup::onMy), 0, .54f);
        button("Browse", 200.f, 228.f, menu_selector(MuchoClansPopup::onBrowse), 0, .54f);
        button("Invites", 318.f, 228.f, menu_selector(MuchoClansPopup::onInvites), 0, .54f);
    }

    void render() {
        m_body->removeAllChildrenWithCleanup(true);
        m_queryInput = m_nameInput = m_tagInput = m_descriptionInput = m_accountInput = nullptr;
        nav();

        switch (m_view) {
            case View::My: {
                if (m_my.isNull()) {
                    label("You are not in a clan yet", 200.f, 174.f, .48f);
                    button("Find a clan", 120.f, 115.f, menu_selector(MuchoClansPopup::onBrowse));
                    button("Create clan", 282.f, 115.f, menu_selector(MuchoClansPopup::onCreateView));
                    return;
                }
                auto name = clean(stringField(m_my, "name"), 25);
                auto tag = clean(stringField(m_my, "tag"), 12);
                auto role = clean(stringField(m_my, "role"), 12);
                label("[" + tag + "] " + name, 200.f, 189.f, .55f);
                label("Role: " + role + "  |  Members: " +
                      std::to_string(intField(m_my, "member_count")) + "/" +
                      std::to_string(intField(m_my, "max_members")),
                      200.f, 160.f, .38f);
                auto members = m_my["members"];
                if (members.isArray()) {
                    std::string names;
                    int count = 0;
                    for (auto const& member : members) {
                        if (count++ >= 3) break;
                        if (!names.empty()) names += ", ";
                        names += clean(stringField(member, "username"), 14);
                    }
                    label("Members: " + clean(names, 72), 200.f, 133.f, .34f);
                }
                button("Refresh", 76.f, 83.f, menu_selector(MuchoClansPopup::onMy), 0, .52f);
                if (role == "owner" || role == "officer")
                    button("Invite", 205.f, 83.f, menu_selector(MuchoClansPopup::onInviteView), 0, .52f);
                if (role != "owner")
                    button("Leave", 324.f, 83.f, menu_selector(MuchoClansPopup::onLeaveView), 0, .52f);
                else label("Owner: transfer ownership before leaving", 200.f, 43.f, .3f);
                break;
            }
            case View::Search: {
                m_queryInput = input("Name or tag", 144.f, 194.f, 224.f, 48);
                m_queryInput->setString(m_query, false);
                button("Search", 328.f, 194.f, menu_selector(MuchoClansPopup::onSearch), 0, .54f);
                if (m_results.empty()) {
                    label("No clans found. Try another query.", 200.f, 133.f, .37f);
                } else {
                    size_t offset = static_cast<size_t>(m_page * 4);
                    for (size_t i = 0; i < 4 && offset + i < m_results.size(); ++i) {
                        auto const& clan = m_results[offset + i];
                        auto name = clean(stringField(clan, "name"), 18);
                        auto tag = clean(stringField(clan, "tag"), 8);
                        auto count = intField(clan, "member_count");
                        label("[" + tag + "] " + name + " (" + std::to_string(count) + ")",
                              146.f, 163.f - i * 29.f, .35f);
                        button("View", 337.f, 163.f - i * 29.f,
                               menu_selector(MuchoClansPopup::onChooseClan),
                               static_cast<int>(offset + i), .46f);
                    }
                    if (m_page > 0)
                        button("<", 165.f, 39.f, menu_selector(MuchoClansPopup::onPrevPage), 0, .45f);
                    if ((offset + 4) < m_results.size())
                        button(">", 237.f, 39.f, menu_selector(MuchoClansPopup::onNextPage), 0, .45f);
                }
                button("Create", 54.f, 39.f, menu_selector(MuchoClansPopup::onCreateView), 0, .43f);
                break;
            }
            case View::Detail: {
                label("[" + clean(stringField(m_selected, "tag"), 10) + "] " +
                      clean(stringField(m_selected, "name"), 30), 200.f, 191.f, .48f);
                label("Owner: " + clean(stringField(m_selected, "owner_username"), 24) +
                      "   Members: " + std::to_string(intField(m_selected, "member_count")),
                      200.f, 163.f, .35f);
                label(clean(stringField(m_selected, "description"), 64), 200.f, 136.f, .32f);
                auto members = m_selected["members"];
                if (members.isArray()) {
                    std::string names;
                    int count = 0;
                    for (auto const& member : members) {
                        if (count++ >= 3) break;
                        if (!names.empty()) names += ", ";
                        names += clean(stringField(member, "username"), 15);
                    }
                    label(clean(names, 72), 200.f, 111.f, .34f);
                }
                bool open = intField(m_selected, "is_open") == 1;
                if (open && m_my.isNull())
                    button("Join clan", 200.f, 70.f, menu_selector(MuchoClansPopup::onJoin), 0, .65f);
                else if (!open) label("Invite only", 200.f, 70.f);
                else label("Leave your current clan to join", 200.f, 70.f, .32f);
                button("Back", 200.f, 36.f, menu_selector(MuchoClansPopup::onBrowse), 0, .46f);
                break;
            }
            case View::Create: {
                label("Create your own clan", 200.f, 193.f, .47f);
                m_nameInput = input("Clan name", 200.f, 151.f, 230.f, 32);
                m_tagInput = input("Tag", 200.f, 112.f, 130.f, 12);
                m_descriptionInput = input("Description (optional)", 200.f, 74.f, 240.f, 100);
                button("Create clan", 200.f, 38.f, menu_selector(MuchoClansPopup::onCreate), 0, .58f);
                break;
            }
            case View::Invites: {
                if (m_invitations.empty()) {
                    label("No pending invitations", 200.f, 150.f, .45f);
                } else {
                    size_t offset = static_cast<size_t>(m_page * 3);
                    for (size_t i = 0; i < 3 && offset + i < m_invitations.size(); ++i) {
                        auto const& invite = m_invitations[offset + i];
                        float y = 186.f - i * 48.f;
                        label("[" + clean(stringField(invite, "tag"), 7) + "] " +
                              clean(stringField(invite, "name"), 18), 127.f, y, .38f);
                        button("Accept", 274.f, y, menu_selector(MuchoClansPopup::onAccept),
                               static_cast<int>(offset + i), .44f);
                        button("X", 358.f, y, menu_selector(MuchoClansPopup::onDecline),
                               static_cast<int>(offset + i), .44f);
                    }
                    if (m_page > 0)
                        button("<", 159.f, 41.f, menu_selector(MuchoClansPopup::onPrevPage), 0, .45f);
                    if (offset + 3 < m_invitations.size())
                        button(">", 246.f, 41.f, menu_selector(MuchoClansPopup::onNextPage), 0, .45f);
                }
                break;
            }
            case View::LeaveConfirm:
                label("Leave your current clan?", 200.f, 160.f, .46f);
                button("Yes, leave", 126.f, 109.f, menu_selector(MuchoClansPopup::onLeave));
                button("Cancel", 279.f, 109.f, menu_selector(MuchoClansPopup::onMy));
                break;
            case View::InviteMember:
                label("Invite an account by its ID", 200.f, 179.f, .43f);
                m_accountInput = input("Account ID", 200.f, 140.f, 170.f, 12);
                m_accountInput->setFilter("0123456789");
                button("Send invite", 200.f, 93.f, menu_selector(MuchoClansPopup::onInvite));
                button("Back", 200.f, 47.f, menu_selector(MuchoClansPopup::onMy), 0, .5f);
                break;
        }
    }

    void request(Action action, std::string const& route,
                 std::vector<std::pair<std::string, std::string>> fields = {}) {
        auto* account = GJAccountManager::sharedState();
        if (!account || account->m_accountID <= 0 || account->m_GJP2.size() != 40 ||
            !std::all_of(account->m_GJP2.begin(), account->m_GJP2.end(),
                [](unsigned char ch) { return std::isxdigit(ch) != 0; })) {
            info("Clans", "Please log into your GDPS account in Geometry Dash first.");
            return;
        }
        std::string body = "accountID=" + std::to_string(account->m_accountID)
                         + "&gameVersion=22&gjp2=" + escaped(std::string(account->m_GJP2.c_str()));
        for (auto const& [key, value] : fields) {
            body += "&" + key + "=" + escaped(value);
        }
        label("Loading...", 200.f, 17.f, .27f);
        m_task.spawn(
            "MuchoCore clans",
            web::WebRequest()
                .timeout(std::chrono::seconds(12))
                .followRedirects(false)
                .header("Content-Type", "application/x-www-form-urlencoded")
                .bodyString(body)
                .post(m_origin + route),
            [this, action](web::WebResponse response) {
                auto raw = response.string().unwrapOr("");
                if (raw == "-1" || raw.size() > 131072) {
                    info("Clans", "Invalid clan response from MuchoCore.");
                    render();
                    return;
                }
                auto parsed = response.json();
                if (!parsed) {
                    info("Clans", "Could not load clan data. Check your connection.");
                    render();
                    return;
                }
                auto root = parsed.unwrap();
                if (!response.ok() || !root["ok"].asBool().unwrapOr(false)) {
                    auto error = stringField(root, "error");
                    info("Clans", error.empty() ? "Server request failed." : error);
                    render();
                    return;
                }
                auto data = root["data"];
                switch (action) {
                    case Action::My:
                        m_my = data;
                        m_view = View::My;
                        break;
                    case Action::Search:
                        m_results.clear();
                        if (data["clans"].isArray())
                            for (auto const& item : data["clans"])
                                if (m_results.size() < 20) m_results.push_back(item);
                        m_page = 0;
                        m_view = View::Search;
                        break;
                    case Action::Detail:
                        m_selected = data;
                        m_view = View::Detail;
                        break;
                    case Action::Invites:
                        m_invitations.clear();
                        if (data["invites"].isArray())
                            for (auto const& item : data["invites"])
                                if (m_invitations.size() < 30) m_invitations.push_back(item);
                        m_page = 0;
                        m_view = View::Invites;
                        break;
                    case Action::Create:
                    case Action::Join:
                    case Action::Leave:
                    case Action::Accept:
                    case Action::Decline:
                        reloadMy();
                        return;
                    case Action::Invite:
                        info("Clans", "Invitation sent.");
                        reloadMy();
                        return;
                }
                render();
            }
        );
    }

    void reloadMy() { request(Action::My, "/api/clans/my"); }

    void onMy(CCObject*) { reloadMy(); }
    void onBrowse(CCObject*) {
        m_view = View::Search;
        m_page = 0;
        render();
        request(Action::Search, "/api/clans/search", {{"query", m_query}});
    }
    void onSearch(CCObject*) {
        if (m_queryInput) m_query = m_queryInput->getString();
        request(Action::Search, "/api/clans/search", {{"query", m_query}});
    }
    void onChooseClan(CCObject* sender) {
        int idx = static_cast<CCNode*>(sender)->getTag();
        if (idx < 0 || static_cast<size_t>(idx) >= m_results.size()) return;
        request(Action::Detail, "/api/clans/get",
                {{"clanID", std::to_string(intField(m_results[idx], "clan_id"))}});
    }
    void onJoin(CCObject*) {
        request(Action::Join, "/api/clans/join",
                {{"clanID", std::to_string(intField(m_selected, "clan_id"))}});
    }
    void onCreateView(CCObject*) { m_view = View::Create; render(); }
    void onCreate(CCObject*) {
        if (!m_nameInput || !m_tagInput) return;
        std::string name = m_nameInput->getString();
        std::string tag = m_tagInput->getString();
        std::string description = m_descriptionInput ? std::string(m_descriptionInput->getString()) : "";
        if (name.empty() || tag.empty()) {
            info("Clans", "Enter a clan name and tag.");
            return;
        }
        request(Action::Create, "/api/clans/create",
                {{"clanName", name}, {"clanTag", tag},
                 {"clanDescription", description}, {"clanOpen", "1"}, {"clanMaxMembers", "50"}});
    }
    void onLeaveView(CCObject*) { m_view = View::LeaveConfirm; render(); }
    void onLeave(CCObject*) { request(Action::Leave, "/api/clans/leave"); }
    void onInvites(CCObject*) { request(Action::Invites, "/api/clans/invites"); }
    void onAccept(CCObject* sender) { respondToInvite(sender, true); }
    void onDecline(CCObject* sender) { respondToInvite(sender, false); }
    void respondToInvite(CCObject* sender, bool accept) {
        int idx = static_cast<CCNode*>(sender)->getTag();
        if (idx < 0 || static_cast<size_t>(idx) >= m_invitations.size()) return;
        request(accept ? Action::Accept : Action::Decline,
                accept ? "/api/clans/invite/accept" : "/api/clans/invite/decline",
                {{"inviteID", std::to_string(intField(m_invitations[idx], "invite_id"))}});
    }
    void onInviteView(CCObject*) { m_view = View::InviteMember; render(); }
    void onInvite(CCObject*) {
        if (!m_accountInput) return;
        auto id = std::string(m_accountInput->getString());
        if (id.empty() || !std::all_of(id.begin(), id.end(),
            [](unsigned char c) { return std::isdigit(c) != 0; })) {
            info("Clans", "Enter a valid numeric account ID.");
            return;
        }
        request(Action::Invite, "/api/clans/invite", {{"targetAccountID", id}});
    }
    void onPrevPage(CCObject*) { if (m_page > 0) --m_page; render(); }
    void onNextPage(CCObject*) { ++m_page; render(); }

protected:
    bool init(std::string origin) {
        if (!Popup::init(400.f, 288.f)) return false;
        m_origin = std::move(origin);
        setTitle("Mucho | Clans");
        m_body = CCNode::create();
        m_mainLayer->addChild(m_body);
        render();
        reloadMy();
        return true;
    }

public:
    static MuchoClansPopup* create(std::string origin) {
        auto popup = new MuchoClansPopup();
        if (popup->init(std::move(origin))) {
            popup->autorelease();
            return popup;
        }
        delete popup;
        return nullptr;
    }
};
