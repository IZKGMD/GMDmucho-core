#pragma once

#include <Geode/Geode.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/ui/TextInput.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoClanRules.hpp"
#include "MuchoUi.hpp"

#include <chrono>
#include <string>
#include <vector>

using namespace geode::prelude;

// HTTPS requests reuse the game's current account. Credentials are never persisted.
class MuchoClansPopup final : public geode::Popup {
    enum class View { My, Browse, Detail, Form, Members, Member, Invites, Sent, Manage, Bans, Invite, Confirm };
    enum class Action { My, Search, Detail, Invites, Sent, Bans, Create, Settings, Join, Leave,
                        Accept, Decline, Invite, Revoke, Kick, Role, Ban, Unban, Transfer, Disband };
    using Fields = std::vector<std::pair<std::string, std::string>>;
    using Rows = std::vector<matjson::Value>;
    struct Draft { std::string name, tag, description, limit = "50"; bool open = true; };

    std::string m_origin, m_query, m_targetInput, m_reason, m_notice;
    View m_view = View::My, m_afterRefresh = View::My, m_back = View::My;
    Action m_confirm = Action::Leave;
    matjson::Value m_my, m_selected, m_member;
    Rows m_results, m_invites, m_sent, m_bans;
    Draft m_draft;
    int m_page = 0, m_searchPage = 0, m_target = 0;
    bool m_loaded = false, m_busy = false, m_closed = false, m_edit = false;
    bool m_remoteSearch = false, m_hasMore = false, m_renderQueued = false;
    unsigned m_generation = 0;
    async::TaskHolder<web::WebResponse> m_task;
    CCNode* m_body = nullptr;
    CCLabelBMFont* m_status = nullptr;
    std::vector<CCMenuItemSpriteExtra*> m_buttons;
    TextInput *m_queryField = nullptr, *m_nameField = nullptr, *m_tagField = nullptr,
              *m_descriptionField = nullptr, *m_limitField = nullptr, *m_targetField = nullptr,
              *m_reasonField = nullptr;

    static std::string clean(std::string text, size_t limit = 120) {
        for (auto& c : text) if (c == '<' || c == '>' || static_cast<unsigned char>(c) < 32) c = ' ';
        if (text.size() > limit) text = text.substr(0, limit - 3) + "...";
        return text;
    }
    static std::string str(matjson::Value const& object, char const* key) {
        return object[key].asString().unwrapOr("");
    }
    static int num(matjson::Value const& object, char const* key) {
        auto const& value = object[key];
        if (auto n = value.asInt()) { auto id = n.unwrap(); return id >= 0 && id <= INT_MAX ? static_cast<int>(id) : 0; }
        return mucho::clans::positiveId(value.asString().unwrapOr(""));
    }
    static Rows rows(matjson::Value const& value) {
        Rows result;
        if (value.isArray()) for (auto const& item : value) {
            if (result.size() == 1000) break;
            result.push_back(item);
        }
        return result;
    }
    int accountId() const {
        auto account = GJAccountManager::sharedState();
        return account ? account->m_accountID : 0;
    }
    std::string role() const { return str(m_my, "role"); }
    bool manager() const { return mucho::clans::canManage(role()); }
    bool owner() const { return role() == "owner"; }
    bool hasClan() const { return m_loaded && m_my.isObject() && num(m_my, "clan_id") > 0; }

    void info(std::string const& message) {
        FLAlertLayer::create("Clans", clean(message, 400).c_str(), "OK")->show();
    }
    void label(std::string const& text, float x, float y, float scale = .38f, float maxWidth = 390.f) {
        auto node = CCLabelBMFont::create(clean(text).c_str(), "bigFont.fnt");
        node->setScale(scale);
        if (node->getScaledContentSize().width > maxWidth)
            node->setScale(maxWidth / node->getContentSize().width);
        node->setPosition({x, y});
        m_body->addChild(node);
    }
    void button(std::string const& text, float x, float y, SEL_MenuHandler handler,
                int tag = 0, float scale = .55f) {
        auto sprite = ButtonSprite::create(clean(text, 26).c_str());
        // Bound wide captions without changing the press-animation baseline.
        scale = std::min(scale, 132.f / sprite->getContentSize().width);
        auto item = mucho::button(sprite, this, handler, scale);
        item->setTag(tag);
        item->setPosition({x, y});
        item->setEnabled(!m_busy);
        auto menu = CCMenu::create();
        menu->setPosition({0.f, 0.f});
        menu->addChild(item);
        m_body->addChild(menu);
        m_buttons.push_back(item);
    }
    TextInput* input(std::string const& hint, float x, float y, float width, size_t limit) {
        auto field = TextInput::create(width, hint, "bigFont.fnt");
        field->setMaxCharCount(limit);
        field->setPosition({x, y});
        m_body->addChild(field);
        return field;
    }
    void capture() {
        if (m_queryField) m_query = m_queryField->getString();
        if (m_nameField) m_draft.name = m_nameField->getString();
        if (m_tagField) m_draft.tag = m_tagField->getString();
        if (m_descriptionField) m_draft.description = m_descriptionField->getString();
        if (m_limitField) m_draft.limit = m_limitField->getString();
        if (m_targetField) m_targetInput = m_targetField->getString();
        if (m_reasonField) m_reason = m_reasonField->getString();
    }
    void busy(bool value, std::string const& status = "") {
        m_busy = value;
        for (auto item : m_buttons) item->setEnabled(!value);
        if (m_status) m_status->setString(clean(status, 80).c_str());
    }
    void redraw() {
        if (m_renderQueued || m_closed) return;
        m_renderQueued = true;
        // A menu callback must finish before removing its sender's parent.
        geode::queueInMainThread([self = Ref<MuchoClansPopup>(this)] {
            self->m_renderQueued = false;
            if (!self->m_closed) self->render();
        });
    }
    void go(View view) {
        if (m_busy || m_closed) return;
        capture(); m_notice.clear(); m_view = view; m_page = 0; redraw();
    }
    void pager(size_t count, size_t perPage) {
        auto pages = std::max(size_t{1}, (count + perPage - 1) / perPage);
        if (m_page >= static_cast<int>(pages)) m_page = static_cast<int>(pages) - 1;
        label("Page " + std::to_string(m_page + 1) + "/" + std::to_string(pages), 210.f, 48.f, .3f);
        if (m_page > 0) button("<", 154.f, 48.f, menu_selector(MuchoClansPopup::onPrev), 0, .42f);
        if (m_page + 1 < static_cast<int>(pages)) button(">", 266.f, 48.f, menu_selector(MuchoClansPopup::onNext), 0, .42f);
    }
    void render() {
        m_body->removeAllChildrenWithCleanup(true);
        m_buttons.clear();
        m_queryField = m_nameField = m_tagField = m_descriptionField = m_limitField = m_targetField = m_reasonField = nullptr;
        button("My Clan", 70.f, 248.f, menu_selector(MuchoClansPopup::onMy), 0, .51f);
        button("Browse", 210.f, 248.f, menu_selector(MuchoClansPopup::onBrowse), 0, .51f);
        button("Invites", 350.f, 248.f, menu_selector(MuchoClansPopup::onInvites), 0, .51f);
        m_status = CCLabelBMFont::create(clean(m_busy ? "Loading..." : m_notice, 80).c_str(), "bigFont.fnt");
        m_status->setScale(.27f); m_status->setPosition({210.f, 17.f}); m_body->addChild(m_status);
        switch (m_view) {
            case View::My:
                if (!m_loaded) {
                    label("Connect your GDPS account", 210.f, 177.f, .5f);
                    label("Log in through the game's Account menu", 210.f, 145.f, .34f);
                    button("Retry", 210.f, 95.f, menu_selector(MuchoClansPopup::onMy));
                } else if (!hasClan()) {
                    label("You are not in a clan yet", 210.f, 177.f, .49f);
                    button("Find a clan", 112.f, 114.f, menu_selector(MuchoClansPopup::onBrowse));
                    button("Create clan", 308.f, 114.f, menu_selector(MuchoClansPopup::onCreateView));
                } else {
                    label("[" + str(m_my, "tag") + "] " + str(m_my, "name"), 210.f, 198.f, .53f);
                    label("Role: " + role() + "  |  Members: " + std::to_string(num(m_my, "member_count")) +
                          "/" + std::to_string(num(m_my, "max_members")), 210.f, 168.f, .36f);
                    label(str(m_my, "description"), 210.f, 138.f, .32f);
                    button("Members", 110.f, 98.f, menu_selector(MuchoClansPopup::onMembers));
                    if (manager()) button("Manage", 310.f, 98.f, menu_selector(MuchoClansPopup::onManage));
                    else button("Leave", 310.f, 98.f, menu_selector(MuchoClansPopup::onLeave));
                    button("Refresh", 210.f, 51.f, menu_selector(MuchoClansPopup::onMy), 0, .46f);
                }
                break;
            case View::Browse: {
                m_queryField = input("Name or tag", 151.f, 202.f, 240.f, 48);
                m_queryField->setString(m_query, false);
                button("Search", 342.f, 202.f, menu_selector(MuchoClansPopup::onSearch), 0, .5f);
                size_t offset = m_remoteSearch ? 0 : static_cast<size_t>(m_searchPage * 4);
                if (m_results.empty()) label("No clans found", 210.f, 133.f, .45f);
                for (size_t i = 0; i < 4 && offset + i < m_results.size(); ++i) {
                    auto const& clan = m_results[offset + i];
                    float y = 166.f - i * 28.f;
                    label("[" + str(clan, "tag") + "] " + str(clan, "name"), 156.f, y, .34f, 269.f);
                    button("View", 351.f, y, menu_selector(MuchoClansPopup::onChooseClan), static_cast<int>(offset + i), .4f);
                }
                label("Page " + std::to_string(m_searchPage + 1), 210.f, 42.f, .3f);
                if (m_searchPage > 0) button("<", 151.f, 42.f, menu_selector(MuchoClansPopup::onPrev), 0, .4f);
                if (m_remoteSearch ? m_hasMore : offset + 4 < m_results.size())
                    button(">", 269.f, 42.f, menu_selector(MuchoClansPopup::onNext), 0, .4f);
                break;
            }
            case View::Detail:
                label("[" + str(m_selected, "tag") + "] " + str(m_selected, "name"), 210.f, 197.f, .5f);
                label("Owner: " + str(m_selected, "owner_username"), 210.f, 168.f, .36f);
                label("Members: " + std::to_string(num(m_selected, "member_count")) + "/" +
                      std::to_string(num(m_selected, "max_members")), 210.f, 141.f, .35f);
                label(str(m_selected, "description"), 210.f, 114.f, .31f);
                button("Members", 113.f, 76.f, menu_selector(MuchoClansPopup::onMembers));
                if (num(m_selected, "clan_id") == num(m_my, "clan_id") && hasClan())
                    button("My Clan", 307.f, 76.f, menu_selector(MuchoClansPopup::onMy));
                else if (!hasClan() && num(m_selected, "is_open") == 1 &&
                         num(m_selected, "member_count") < num(m_selected, "max_members"))
                    button("Join clan", 307.f, 76.f, menu_selector(MuchoClansPopup::onJoin));
                else label(hasClan() ? "Already in a clan" : num(m_selected, "is_open") ? "Clan full" : "Invite only", 307.f, 76.f, .32f, 160.f);
                button("Back", 210.f, 38.f, menu_selector(MuchoClansPopup::onBrowse), 0, .42f);
                break;
            case View::Form:
                label(m_edit ? "Clan settings" : "Create a clan", 210.f, 214.f, .45f);
                m_nameField = input("Name: 2-24 characters", 152.f, 175.f, 225.f, 24);
                m_tagField = input("Tag: 2-6", 335.f, 175.f, 105.f, 6);
                m_descriptionField = input("Description (optional)", 210.f, 132.f, 350.f, 160);
                m_limitField = input("Max members", 121.f, 89.f, 156.f, 3);
                m_limitField->setFilter("0123456789");
                m_nameField->setString(m_draft.name, false); m_tagField->setString(m_draft.tag, false);
                m_descriptionField->setString(m_draft.description, false); m_limitField->setString(m_draft.limit, false);
                button(m_draft.open ? "Open clan" : "Invite only", 310.f, 89.f, menu_selector(MuchoClansPopup::onToggleOpen), 0, .48f);
                button(m_edit ? "Save" : "Create", 310.f, 44.f, menu_selector(MuchoClansPopup::onSave));
                button("Cancel", 110.f, 44.f, menu_selector(MuchoClansPopup::onBack));
                break;
            case View::Members: {
                auto members = rows(m_back == View::Detail ? m_selected["members"] : m_my["members"]);
                if (members.empty()) label("No members to show", 210.f, 148.f);
                pager(members.size(), 4);
                for (size_t i = static_cast<size_t>(m_page * 4); i < members.size() && i < static_cast<size_t>((m_page + 1) * 4); ++i) {
                    auto const& member = members[i]; float y = 205.f - (i % 4) * 37.f;
                    label(str(member, "username"), 108.f, y, .39f, 164.f);
                    label(str(member, "role"), 239.f, y, .3f, 85.f);
                    button("View", 353.f, y, menu_selector(MuchoClansPopup::onMember), static_cast<int>(i), .42f);
                }
                button("Back", 54.f, 47.f, menu_selector(MuchoClansPopup::onBack), 0, .42f);
                break;
            }
            case View::Member: {
                label(str(m_member, "username"), 210.f, 201.f, .53f);
                label("Account " + std::to_string(num(m_member, "account_id")) + "  |  " + str(m_member, "role"), 210.f, 170.f, .35f);
                bool own = m_back != View::Detail || num(m_selected, "clan_id") == num(m_my, "clan_id");
                bool allowed = own && mucho::clans::canModerate(role(), accountId(), str(m_member, "role"), num(m_member, "account_id"));
                if (allowed) {
                    if (owner()) {
                        button(str(m_member, "role") == "officer" ? "Demote" : "Promote", 110.f, 125.f, menu_selector(MuchoClansPopup::onRole));
                        button("Make owner", 310.f, 125.f, menu_selector(MuchoClansPopup::onTransfer));
                    }
                    button("Kick", 110.f, 83.f, menu_selector(MuchoClansPopup::onKick));
                    button("Ban", 310.f, 83.f, menu_selector(MuchoClansPopup::onBan));
                }
                button("Back", 210.f, 41.f, menu_selector(MuchoClansPopup::onMembersBack), 0, .46f);
                break;
            }
            case View::Invites:
            case View::Sent: {
                bool sent = m_view == View::Sent;
                auto const& items = sent ? m_sent : m_invites;
                label(sent ? "Sent invitations" : "Received invitations", 210.f, 216.f, .4f);
                if (items.empty()) label("No pending invitations", 210.f, 146.f, .4f);
                pager(items.size(), 3);
                for (size_t i = static_cast<size_t>(m_page * 3); i < items.size() && i < static_cast<size_t>((m_page + 1) * 3); ++i) {
                    auto const& item = items[i]; float y = 184.f - (i % 3) * 43.f;
                    label(sent ? str(item, "username") : "[" + str(item, "tag") + "] " + str(item, "name"), 124.f, y, .34f, 211.f);
                    label("Expires: " + str(item, "expires_at"), 124.f, y - 15.f, .22f, 211.f);
                    if (sent) button("Revoke", 331.f, y, menu_selector(MuchoClansPopup::onRevoke), static_cast<int>(i), .45f);
                    else {
                        if (!hasClan()) button("Accept", 278.f, y, menu_selector(MuchoClansPopup::onAccept), static_cast<int>(i), .4f);
                        button("Decline", 364.f, y, menu_selector(MuchoClansPopup::onDecline), static_cast<int>(i), .36f);
                    }
                }
                if (sent) button("Back", 51.f, 46.f, menu_selector(MuchoClansPopup::onManage), 0, .4f);
                break;
            }
            case View::Manage:
                label("Manage [" + str(m_my, "tag") + "]", 210.f, 211.f, .47f);
                button("Invite player", 110.f, 168.f, menu_selector(MuchoClansPopup::onInviteView));
                button("Sent invites", 310.f, 168.f, menu_selector(MuchoClansPopup::onSent));
                button("Members", 110.f, 124.f, menu_selector(MuchoClansPopup::onMembers));
                button("Bans", 310.f, 124.f, menu_selector(MuchoClansPopup::onBans));
                if (owner()) {
                    button("Settings", 110.f, 80.f, menu_selector(MuchoClansPopup::onSettings));
                    button("Disband", 310.f, 80.f, menu_selector(MuchoClansPopup::onDisband));
                } else button("Leave clan", 210.f, 80.f, menu_selector(MuchoClansPopup::onLeave));
                button("Back", 210.f, 40.f, menu_selector(MuchoClansPopup::onMy), 0, .42f);
                break;
            case View::Bans:
                label("Banned players", 210.f, 216.f, .45f);
                if (m_bans.empty()) label("No active bans", 210.f, 143.f);
                pager(m_bans.size(), 3);
                for (size_t i = static_cast<size_t>(m_page * 3); i < m_bans.size() && i < static_cast<size_t>((m_page + 1) * 3); ++i) {
                    auto const& item = m_bans[i]; float y = 184.f - (i % 3) * 43.f;
                    label(str(item, "username"), 139.f, y, .36f, 236.f);
                    label(str(item, "reason"), 139.f, y - 16.f, .25f, 236.f);
                    button("Unban", 334.f, y, menu_selector(MuchoClansPopup::onUnban), static_cast<int>(i), .47f);
                }
                button("Back", 51.f, 46.f, menu_selector(MuchoClansPopup::onManage), 0, .4f);
                break;
            case View::Invite:
                label("Invite a player by account ID", 210.f, 185.f, .44f);
                m_targetField = input("Account ID", 210.f, 136.f, 206.f, 10);
                m_targetField->setFilter("0123456789"); m_targetField->setString(m_targetInput, false);
                button("Send invite", 210.f, 85.f, menu_selector(MuchoClansPopup::onInvite));
                button("Back", 210.f, 42.f, menu_selector(MuchoClansPopup::onManage), 0, .43f);
                break;
            case View::Confirm:
                label(confirmTitle(), 210.f, 188.f, .5f);
                label(m_confirm == Action::Disband ? "All members and invites will be removed" :
                      m_confirm == Action::Transfer ? "You will become an officer" :
                      m_confirm == Action::Kick || m_confirm == Action::Ban ? str(m_member, "username") : "Confirm this action", 210.f, 153.f, .34f);
                if (m_confirm == Action::Ban) {
                    m_reasonField = input("Ban reason (optional)", 210.f, 114.f, 310.f, 160);
                    m_reasonField->setString(m_reason, false);
                }
                button("Confirm", 110.f, 75.f, menu_selector(MuchoClansPopup::onConfirm));
                button("Cancel", 310.f, 75.f, menu_selector(MuchoClansPopup::onCancel));
                break;
        }
    }

    std::string confirmTitle() const {
        switch (m_confirm) {
            case Action::Kick: return "Kick this member?";
            case Action::Ban: return "Ban this member?";
            case Action::Transfer: return "Transfer ownership?";
            case Action::Disband: return "Disband your clan?";
            case Action::Revoke: return "Revoke this invitation?";
            case Action::Unban: return "Remove this ban?";
            case Action::Accept: return "Join this invited clan?";
            case Action::Join: return "Join this clan?";
            default: return "Leave your clan?";
        }
    }
    void confirm(Action action, int target = 0) {
        if (m_busy) return;
        capture(); m_confirm = action; m_target = target; m_reason.clear();
        m_afterRefresh = m_view; go(View::Confirm);
    }
    void fail(std::string message) {
        busy(false, message); info(message);
    }
    static char const* resultKey(Action action) {
        switch (action) {
            case Action::Join: return "joined"; case Action::Leave: return "left";
            case Action::Accept: return "accepted"; case Action::Decline: return "declined";
            case Action::Invite: return "invited"; case Action::Revoke: return "revoked";
            case Action::Kick: return "kicked"; case Action::Role: return "updated";
            case Action::Ban: return "banned"; case Action::Unban: return "unbanned";
            case Action::Transfer: return "transferred"; case Action::Disband: return "disbanded";
            default: return nullptr;
        }
    }
    void request(Action action, std::string const& route, Fields fields = {}) {
        if (m_busy || m_closed) return;
        capture();
        auto* account = GJAccountManager::sharedState();
        std::string credential = account ? std::string(account->m_GJP2.c_str()) : "";
        if (!account || account->m_accountID <= 0 || credential.size() != 40 ||
            !std::all_of(credential.begin(), credential.end(), [](unsigned char c) {
                return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
            })) { fail("Log into your GDPS account in the game's Account menu, then press Retry."); return; }
        std::string body = "accountID=" + std::to_string(account->m_accountID) +
                           "&gameVersion=22&gjp2=" + mucho::clans::encoded(credential);
        for (auto const& [key, value] : fields) body += "&" + key + "=" + mucho::clans::encoded(value);
        int sentAccount = account->m_accountID;
        int requestedPage = m_searchPage;
        if (action == Action::Search) for (auto const& [key, value] : fields)
            if (key == "offset") requestedPage = mucho::clans::positiveId(value) / 4;
        auto generation = ++m_generation;
        busy(true, "Loading...");
        m_task.spawn("MuchoCore clans", web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false)
            .header("Content-Type", "application/x-www-form-urlencoded").bodyString(body).post(m_origin + route),
            [this, action, generation, sentAccount, requestedPage](web::WebResponse response) {
                if (m_closed || generation != m_generation) return;
                if (accountId() != sentAccount) { m_loaded = false; fail("The account changed. Close Clans and open it again."); return; }
                busy(false);
                auto raw = response.string().unwrapOr("");
                if (raw.size() > 1048576) { fail("Clan response is too large."); return; }
                if (response.code() == 429) { fail("Too many requests. Wait a moment and retry."); return; }
                if (response.code() == 404 || raw == "-1") { fail("Clan API is unavailable. Update MuchoCore on your server."); return; }
                auto parsed = response.json();
                if (!parsed) { fail("The server did not return clan data. Check the connection and MuchoCore logs."); return; }
                auto root = parsed.unwrap();
                if (!response.ok() || !root["ok"].asBool().unwrapOr(false)) {
                    auto error = str(root, "error"); fail(error.empty() ? "Clan request failed. Please retry." : error); return;
                }
                auto data = root["data"];
                if (auto key = resultKey(action); key && !data[key].asBool().unwrapOr(false)) {
                    fail("Nothing changed. The invitation or member may no longer exist. Refresh and try again."); return;
                }
                switch (action) {
                    case Action::My:
                        if (!data.isNull() && (!data.isObject() || num(data, "clan_id") <= 0)) { fail("Invalid clan data returned by the server."); return; }
                        m_my = data; m_loaded = true;
                        m_view = hasClan() ? m_afterRefresh : View::My;
                        if (!manager() && m_view == View::Manage) m_view = View::My;
                        m_page = 0; break;
                    case Action::Search:
                        if (!data["clans"].isArray()) { fail("Invalid clan search response."); return; }
                        m_results = rows(data["clans"]); m_remoteSearch = data["has_more"].isBool();
                        m_searchPage = requestedPage;
                        m_hasMore = data["has_more"].asBool().unwrapOr(false); m_view = View::Browse; break;
                    case Action::Detail:
                        if (!data.isObject() || num(data, "clan_id") <= 0) { fail("Invalid clan details."); return; }
                        m_selected = data; m_view = View::Detail; break;
                    case Action::Invites: m_invites = rows(data["invites"]); m_view = View::Invites; m_page = 0; break;
                    case Action::Sent: m_sent = rows(data["invites"]); m_view = View::Sent; m_page = 0; break;
                    case Action::Bans: m_bans = rows(data["bans"]); m_view = View::Bans; m_page = 0; break;
                    case Action::Decline: request(Action::Invites, "/api/clans/invites"); return;
                    case Action::Invite:
                        m_targetInput.clear(); m_notice = "Invitation sent";
                        m_afterRefresh = View::Manage; request(Action::My, "/api/clans/my"); return;
                    case Action::Revoke: request(Action::Sent, "/api/clans/invites/sent"); return;
                    case Action::Unban: request(Action::Bans, "/api/clans/bans"); return;
                    default:
                        m_afterRefresh = action == Action::Kick || action == Action::Ban || action == Action::Role ? View::Members :
                                         action == Action::Settings || action == Action::Transfer ? View::Manage : View::My;
                        m_back = View::My; m_notice = "Saved";
                        request(Action::My, "/api/clans/my"); return;
                }
                redraw();
            });
    }
    void search(bool reset, int page = -1) {
        if (m_busy) return;
        capture(); int requested = reset ? 0 : page >= 0 ? page : m_searchPage;
        request(Action::Search, "/api/clans/search", {{"query", m_query}, {"offset", std::to_string(requested * 4)}, {"limit", "4"}});
    }
    void onMy(CCObject*) { m_afterRefresh = View::My; request(Action::My, "/api/clans/my"); }
    void onBrowse(CCObject*) { search(true); }
    void onSearch(CCObject*) { search(true); }
    void onInvites(CCObject*) { request(Action::Invites, "/api/clans/invites"); }
    void onSent(CCObject*) { request(Action::Sent, "/api/clans/invites/sent"); }
    void onBans(CCObject*) { request(Action::Bans, "/api/clans/bans"); }
    void onManage(CCObject*) { if (manager()) go(View::Manage); }
    void onChooseClan(CCObject* sender) {
        int i = static_cast<CCNode*>(sender)->getTag();
        if (i >= 0 && static_cast<size_t>(i) < m_results.size())
            request(Action::Detail, "/api/clans/get", {{"clanID", std::to_string(num(m_results[i], "clan_id"))}});
    }
    void onMembers(CCObject*) { if (m_view != View::Member) m_back = m_view == View::Detail ? View::Detail : View::My; go(View::Members); }
    void onMembersBack(CCObject*) { go(View::Members); }
    void onMember(CCObject* sender) {
        int i = static_cast<CCNode*>(sender)->getTag();
        auto members = rows(m_back == View::Detail ? m_selected["members"] : m_my["members"]);
        if (i >= 0 && static_cast<size_t>(i) < members.size()) { m_member = members[i]; go(View::Member); }
    }
    void onBack(CCObject*) { go(m_view == View::Form ? m_edit ? View::Manage : View::My : m_back); }
    void onCreateView(CCObject*) { m_edit = false; m_draft = Draft{}; go(View::Form); }
    void onSettings(CCObject*) {
        if (!owner()) return;
        m_edit = true; m_draft = {str(m_my, "name"), str(m_my, "tag"), str(m_my, "description"), std::to_string(num(m_my, "max_members")), num(m_my, "is_open") == 1};
        go(View::Form);
    }
    void onToggleOpen(CCObject*) { if (!m_busy) { capture(); m_draft.open = !m_draft.open; redraw(); } }
    void onSave(CCObject*) {
        capture();
        auto name = mucho::clans::normalizedName(m_draft.name), tag = mucho::clans::normalizedTag(m_draft.tag);
        auto limit = mucho::clans::positiveId(m_draft.limit);
        if (name.empty()) { info("Name: 2-24 Latin letters, digits, spaces, dot, dash or underscore. Start with a letter or digit."); return; }
        if (tag.empty()) { info("Tag: 2-6 Latin letters or digits."); return; }
        if (limit < 2 || limit > 500 || (m_edit && limit < num(m_my, "member_count"))) { info("Choose a limit from 2 to 500, at least the current member count."); return; }
        Fields fields = {{"clanName", name}, {"clanTag", tag}, {"clanDescription", m_draft.description},
                         {"clanOpen", m_draft.open ? "1" : "0"}, {"clanMaxMembers", std::to_string(limit)}};
        if (m_edit) fields.push_back({"clanID", std::to_string(num(m_my, "clan_id"))});
        request(m_edit ? Action::Settings : Action::Create, m_edit ? "/api/clans/settings" : "/api/clans/create", std::move(fields));
    }
    void onJoin(CCObject*) { confirm(Action::Join, num(m_selected, "clan_id")); }
    void onLeave(CCObject*) { confirm(Action::Leave); }
    void onKick(CCObject*) { confirm(Action::Kick, num(m_member, "account_id")); }
    void onBan(CCObject*) { confirm(Action::Ban, num(m_member, "account_id")); }
    void onTransfer(CCObject*) { confirm(Action::Transfer, num(m_member, "account_id")); }
    void onDisband(CCObject*) { confirm(Action::Disband); }
    void onRole(CCObject*) { request(Action::Role, "/api/clans/role", {{"targetAccountID", std::to_string(num(m_member, "account_id"))}, {"role", str(m_member, "role") == "officer" ? "member" : "officer"}}); }
    void onInviteView(CCObject*) { go(View::Invite); }
    void onInvite(CCObject*) {
        capture(); auto id = mucho::clans::positiveId(m_targetInput);
        if (id <= 0 || id == accountId()) { info("Enter another player's valid account ID."); return; }
        request(Action::Invite, "/api/clans/invite", {{"targetAccountID", std::to_string(id)}});
    }
    void onAccept(CCObject* sender) {
        int i = static_cast<CCNode*>(sender)->getTag();
        if (i >= 0 && static_cast<size_t>(i) < m_invites.size()) confirm(Action::Accept, num(m_invites[i], "invite_id"));
    }
    void onDecline(CCObject* sender) {
        int i = static_cast<CCNode*>(sender)->getTag();
        if (i >= 0 && static_cast<size_t>(i) < m_invites.size()) request(Action::Decline, "/api/clans/invite/decline", {{"inviteID", std::to_string(num(m_invites[i], "invite_id"))}});
    }
    void onRevoke(CCObject* sender) {
        int i = static_cast<CCNode*>(sender)->getTag();
        if (i >= 0 && static_cast<size_t>(i) < m_sent.size()) confirm(Action::Revoke, num(m_sent[i], "invite_id"));
    }
    void onUnban(CCObject* sender) {
        int i = static_cast<CCNode*>(sender)->getTag();
        if (i >= 0 && static_cast<size_t>(i) < m_bans.size()) confirm(Action::Unban, num(m_bans[i], "account_id"));
    }
    void onCancel(CCObject*) { go(m_afterRefresh); }
    void onConfirm(CCObject*) {
        capture();
        Fields target = {{"targetAccountID", std::to_string(m_target)}};
        switch (m_confirm) {
            case Action::Join: request(m_confirm, "/api/clans/join", {{"clanID", std::to_string(m_target)}}); break;
            case Action::Leave: request(m_confirm, "/api/clans/leave"); break;
            case Action::Kick: request(m_confirm, "/api/clans/kick", target); break;
            case Action::Ban: target.push_back({"reason", m_reason}); request(m_confirm, "/api/clans/ban", target); break;
            case Action::Transfer: request(m_confirm, "/api/clans/transfer", target); break;
            case Action::Disband: request(m_confirm, "/api/clans/disband"); break;
            case Action::Revoke: request(m_confirm, "/api/clans/invite/revoke", {{"inviteID", std::to_string(m_target)}}); break;
            case Action::Unban: request(m_confirm, "/api/clans/unban", target); break;
            case Action::Accept: request(m_confirm, "/api/clans/invite/accept", {{"inviteID", std::to_string(m_target)}}); break;
            default: break;
        }
    }
    void onPrev(CCObject*) {
        if (m_busy) return;
        if (m_view == View::Browse) { if (m_searchPage == 0) return; if (m_remoteSearch) search(false, m_searchPage - 1); else { --m_searchPage; redraw(); } }
        else { if (m_page > 0) --m_page; redraw(); }
    }
    void onNext(CCObject*) {
        if (m_busy) return;
        if (m_view == View::Browse) { if (m_remoteSearch) search(false, m_searchPage + 1); else { ++m_searchPage; redraw(); } }
        else { ++m_page; redraw(); }
    }

protected:
    bool init(std::string origin) {
        if (!Popup::init(420.f, 306.f)) return false;
        m_origin = std::move(origin); setTitle("Mucho | Clans v0.4.0");
        m_body = CCNode::create(); m_mainLayer->addChild(m_body);
        render(); request(Action::My, "/api/clans/my"); return true;
    }
    void onClose(CCObject* sender) override {
        m_closed = true; ++m_generation; m_task.cancel(); Popup::onClose(sender);
    }
    void onExit() override {
        m_closed = true; ++m_generation; m_task.cancel(); Popup::onExit();
    }
public:
    static MuchoClansPopup* create(std::string origin) {
        auto popup = new MuchoClansPopup();
        if (popup->init(std::move(origin))) { popup->autorelease(); return popup; }
        delete popup; return nullptr;
    }
};
