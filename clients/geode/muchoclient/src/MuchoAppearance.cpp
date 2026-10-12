#include "MuchoAppearance.hpp"
#include "MuchoProfileHeader.hpp"
#include "MuchoScoreAvatar.hpp"
#include "MuchoLayoutRules.hpp"
#include <Geode/modify/ProfilePage.hpp>
#include <Geode/modify/GJScoreCell.hpp>
#include <Geode/modify/CommentCell.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>

#include <chrono>
#include <algorithm>
#include <map>
#include <set>
#include <vector>

using namespace geode::prelude;

namespace mucho::appearance {
namespace {
    using Clock = std::chrono::steady_clock;
    struct Style { std::string name, tag, nickname, prefix; Clock::time_point expires; };
    struct Binding {
        WeakRef<CCNode> root;
        WeakRef<CCLabelBMFont> label;
        int account;
        std::string original, applied, font;
        ccColor3B color;
        float width, scale;
        bool alignLeft = false;
        float left = 0;
        CCPoint position, anchor;
        WeakRef<CCMenuItemSpriteExtra> button;
        CCSize buttonSize;
        CCPoint buttonPosition;
    };

    class Styles {
        std::string m_origin;
        std::map<int, Style> m_cache;
        std::map<int, Clock::time_point> m_retry;
        std::set<int> m_pending;
        std::set<int> m_inflight;
        std::vector<Binding> m_bindings;
        async::TaskHolder<web::WebResponse> m_task;
        bool m_busy = false, m_queued = false;
        unsigned m_generation = 0;

        bool live(Binding const& binding) {
            auto root = binding.root.lock();
            auto label = binding.label.lock();
            if (!root || !label) return false;
            auto parent = label->getParent();
            while (parent && parent != root.data()) parent = parent->getParent();
            if (!parent) return false;
            std::string text = label->getString();
            return text == binding.original || text == binding.applied;
        }

        void update() {
            std::erase_if(m_bindings, [this](auto const& b) { return !live(b); });
            for (auto& binding : m_bindings) {
                auto found = m_cache.find(binding.account);
                if (found == m_cache.end()) continue;
                auto label = binding.label.lock();
                if (!label) continue;
                auto const& style = found->second;
                label->setFntFile(binding.font.c_str());
                paint(label.data(), style.name, style.tag, style.nickname, style.prefix, binding.width, binding.scale);
                if (binding.alignLeft) {
                    // A changed label does not resize its native menu item.
                    // Keep the original left edge, fit before the statistics,
                    // then resize the clickable area without moving that edge.
                    label->setAnchorPoint({0.f,binding.anchor.y});
                    label->setPosition({binding.left,binding.position.y});
                    if (auto button = binding.button.lock()) {
                        float width = std::max(1.f,binding.left+label->getScaledContentSize().width);
                        button->setContentSize({width,binding.buttonSize.height});
                        button->setPosition({mucho::layout::resizedButtonX(binding.buttonPosition.x,binding.buttonSize.width,
                            width,button->getAnchorPoint().x,button->getScaleX()),binding.buttonPosition.y});
                    }
                }
                if (style.tag.empty() && style.nickname.empty()) label->setColor(binding.color);
                binding.applied = label->getString();
            }
        }

        void queue() {
            if (m_queued || m_busy || m_pending.empty()) return;
            m_queued = true;
            geode::queueInMainThread([this] { m_queued = false; flush(); });
        }

        void flush() {
            syncOrigin();
            if (m_busy || m_pending.empty() || !validOrigin(m_origin)) return;
            std::set<int> accounts;
            std::string ids;
            while (!m_pending.empty() && accounts.size() < 50) {
                int id = *m_pending.begin(); m_pending.erase(m_pending.begin()); accounts.insert(id);
                if (!ids.empty()) ids += ',';
                ids += std::to_string(id);
            }
            m_busy = true;
            m_inflight = accounts;
            auto generation = m_generation;
            m_task.spawn("MuchoCore name colors", web::WebRequest().timeout(std::chrono::seconds(8))
                .followRedirects(false).header("Content-Type", "application/x-www-form-urlencoded")
                .bodyString("accountIDs=" + clans::encoded(ids)).post(m_origin + "/api/appearance/batch"),
                [this, accounts, generation](web::WebResponse response) {
                    syncOrigin();
                    if (generation != m_generation) return;
                    m_busy = false;
                    m_inflight.clear();
                    auto now = Clock::now();
                    std::erase_if(m_retry, [now](auto const& entry) { return entry.second <= now; });
                    if (m_retry.size() > 512) m_retry.clear();
                    for (int id : accounts) m_retry[id] = now + std::chrono::seconds(30);
                    auto raw = response.string().unwrapOr("");
                    if (response.ok() && raw.size() <= 65536) {
                        auto json = response.json();
                        if (json && json.unwrap()["ok"].asBool().unwrapOr(false)) {
                            auto const& players = json.unwrap()["data"]["players"];
                            if (players.isArray() && players.size() <= 50) for (auto const& player : players) {
                                int id = static_cast<int>(player["account_id"].asInt().unwrapOr(0));
                                if (accounts.contains(id)) put(player);
                            }
                        }
                    }
                    update(); queue();
                });
        }

        void syncOrigin() {
            auto origin = Mod::get()->getSettingValue<std::string>("server-url");
            while (!origin.empty() && origin.back() == '/') origin.pop_back();
            if (origin == m_origin) return;
            ++m_generation; m_task.cancel(); m_busy = false;
            m_origin = origin; m_cache.clear(); m_pending.clear(); m_retry.clear(); m_inflight.clear();
            for (auto& b : m_bindings) if (live(b)) {
                if (auto label = b.label.lock()) {
                    label->setFntFile(b.font.c_str()); label->setString(b.original.c_str());
                    label->setColor(b.color); label->setScale(b.scale); b.applied.clear();
                    if (b.alignLeft) {
                        label->setAnchorPoint(b.anchor); label->setPosition(b.position);
                        if (auto button = b.button.lock()) {
                            button->setContentSize(b.buttonSize); button->setPosition(b.buttonPosition);
                        }
                    }
                }
            }
        }

    public:
        void put(matjson::Value const& player) {
            auto id64 = player["account_id"].asInt().unwrapOr(0);
            auto name = player["username"].asString().unwrapOr("");
            auto rawTag = player["clan_tag"].asString().unwrapOr("");
            auto tag = clans::normalizedTag(rawTag);
            auto nickname = player["nickname_color"].asString().unwrapOr("");
            auto prefix = player["prefix_color"].asString().unwrapOr("FFD166");
            if (id64 <= 0 || id64 > INT_MAX || name.empty() || name.size() > 24 ||
                (!rawTag.empty() && tag.empty()) || (!nickname.empty() && !parsedColor(nickname)) || !parsedColor(prefix)) return;
            for (unsigned char c : name) if (c < 32 || c == '<' || c == '>') return;
            auto now = Clock::now();
            if (m_cache.size() >= 512 && !m_cache.contains(static_cast<int>(id64))) {
                auto oldest = std::min_element(m_cache.begin(), m_cache.end(), [](auto const& a, auto const& b) { return a.second.expires < b.second.expires; });
                m_cache.erase(oldest);
            }
            m_cache[static_cast<int>(id64)] = {name, tag, nickname, prefix, now + std::chrono::seconds(60)};
            m_retry.erase(static_cast<int>(id64));
        }

        void add(CCNode* root, CCLabelBMFont* label, int account, float width, bool alignLeft) {
            syncOrigin();
            if (!root || !label || account <= 0 || !validOrigin(m_origin)) return;
            std::erase_if(m_bindings, [this, label](auto const& b) { return !live(b) || b.label.lock().data() == label; });
            // Font, scale and width are restored before tinting and on server changes.
            Binding binding{root, label, account, label->getString(), "", label->getFntFile(), label->getColor(), width, label->getScale()};
            binding.alignLeft = alignLeft; binding.position = label->getPosition(); binding.anchor = label->getAnchorPoint();
            binding.left = binding.position.x - binding.anchor.x * label->getScaledContentSize().width;
            if (alignLeft) if (auto button = typeinfo_cast<CCMenuItemSpriteExtra*>(label->getParent())) {
                binding.button = button; binding.buttonSize = button->getContentSize(); binding.buttonPosition = button->getPosition();
            }
            m_bindings.push_back(std::move(binding));
            auto now = Clock::now();
            auto cache = m_cache.find(account);
            if (cache == m_cache.end() || cache->second.expires <= now) {
                if (!m_inflight.contains(account) && (!m_retry.contains(account) || m_retry[account] <= now)) m_pending.insert(account);
            }
            update(); queue();
        }

        void reload() {
            syncOrigin(); ++m_generation; m_task.cancel(); m_busy = false;
            m_cache.clear(); m_retry.clear(); m_pending.clear(); m_inflight.clear();
            std::erase_if(m_bindings, [this](auto const& b) { return !live(b); });
            for (auto const& b : m_bindings) m_pending.insert(b.account);
            queue();
        }

        void saved(matjson::Value const& player) { syncOrigin(); put(player); update(); }
    };

    Styles& styles() { static Styles instance; return instance; }

    CCLabelBMFont* namedLabel(CCNode* root, std::string const& name) {
        if (!root || name.empty()) return nullptr;
        if (auto label = typeinfo_cast<CCLabelBMFont*>(root); label && name == label->getString()) {
            for (auto parent = label->getParent(); parent; parent = parent->getParent())
                if (typeinfo_cast<CCMenuItemSpriteExtra*>(parent)) return label;
        }
        for (auto child : CCArrayExt<CCNode*>(root->getChildren())) {
            if (auto label = namedLabel(child, name)) return label;
        }
        return nullptr;
    }

    CCRect boundsIn(CCNode* node, CCNode* space) {
        auto size = node->getContentSize();
        auto a = space->convertToNodeSpace(node->convertToWorldSpace({0.f,0.f}));
        auto b = space->convertToNodeSpace(node->convertToWorldSpace({size.width,0.f}));
        auto c = space->convertToNodeSpace(node->convertToWorldSpace({0.f,size.height}));
        auto d = space->convertToNodeSpace(node->convertToWorldSpace({size.width,size.height}));
        float left = std::min({a.x,b.x,c.x,d.x}), right = std::max({a.x,b.x,c.x,d.x});
        float bottom = std::min({a.y,b.y,c.y,d.y}), top = std::max({a.y,b.y,c.y,d.y});
        return {left,bottom,right-left,top-bottom};
    }

    float scoreNameWidth(CCNode* root, CCLabelBMFont* label, float cellWidth) {
        auto space = label->getParent();
        auto name = boundsIn(label,space);
        float right = space->convertToNodeSpace(root->convertToWorldSpace({cellWidth-8.f,0.f})).x;
        auto reserve = [&](CCNode* node) {
            if (!node || !node->isVisible()) return;
            auto rect = boundsIn(node,space);
            if (rect.getMinX() > name.getMinX() && rect.getMaxY() > name.getMinY() && rect.getMinY() < name.getMaxY())
                right = std::min(right,rect.getMinX());
        };
        for (auto id : {"stars-label","stars-icon","moons-label","moons-icon","demons-label","demons-icon", "user-coins-label","user-coins-icon"})
            reserve(root->getChildByIDRecursive(id));
        // Numeric labels are also a fallback when node IDs are unavailable.
        auto scan = [&](auto&& self, CCNode* node) -> void {
            if (!node->isVisible()) return;
            if (auto number = typeinfo_cast<CCLabelBMFont*>(node); number && number != label) {
                std::string text = number->getString();
                if (!text.empty() && std::all_of(text.begin(),text.end(),[](unsigned char c) { return (c >= '0' && c <= '9') || c == ',' || c == '.'; })) reserve(number);
            }
            for (auto child : CCArrayExt<CCNode*>(node->getChildren())) self(self,child);
        };
        scan(scan,root);
        return mucho::layout::nameWidth(name.getMinX(),right);
    }
}

    void bind(CCNode* root, CCLabelBMFont* label, int account, float width, bool alignLeft) { styles().add(root, label, account, width, alignLeft); }
    void remember(matjson::Value const& player) { styles().saved(player); }
    void invalidate() { styles().reload(); }
}

class $modify(MuchoColorProfile, ProfilePage) {
    void onMuchoProfile(CCObject*) {
        if (auto popup = MuchoProfilePopup::create(mucho::profile::origin(),m_accountID)) popup->show();
    }
    void loadPageFromUserInfo(GJUserScore* score) {
        ProfilePage::loadPageFromUserInfo(score);
        if (score && m_usernameLabel) mucho::appearance::bind(this, m_usernameLabel, score->m_accountID, score->m_modBadge > 0 ? 140.f : 160.f);
        if (!score || !m_mainLayer || score->m_accountID <= 0) return;
        if (auto old = m_mainLayer->getChildByID("mucho-profile-header")) old->removeFromParent();
        if (auto old = m_mainLayer->getChildByID("mucho-profile-menu")) old->removeFromParent();
        auto origin = mucho::profile::origin();
        if (!mucho::appearance::validOrigin(origin)) return;
        // The profile can be moved/scaled by GD or other mods. Keep artwork
        // inside its own background, in the same coordinate space as the label.
        auto background = m_mainLayer->getChildByID("background");
        if (!background) background = m_mainLayer->getChildByType<CCScale9Sprite>(0);
        if (!background || !m_usernameLabel) return;
        auto bounds = background->boundingBox();
        if (bounds.size.width < 180 || bounds.size.height < 80) return;
        auto nameCenter = m_mainLayer->convertToNodeSpace(m_usernameLabel->convertToWorldSpace(m_usernameLabel->getContentSize()/2));
        CCSize headerSize{std::min(344.f,bounds.size.width-80.f),28.f};
        float headerY = std::clamp(nameCenter.y-headerSize.height/2,bounds.getMinY()+8.f,bounds.getMaxY()-8.f-headerSize.height);
        if (auto header = mucho::profile::Header::create(origin,score->m_accountID,headerSize)) {
            header->setID("mucho-profile-header"); header->setPosition({bounds.getMidX()-headerSize.width/2,headerY});
            m_mainLayer->addChild(header,1);
        }
        auto menu = CCMenu::create(); menu->setID("mucho-profile-menu");
        menu->setPosition({bounds.getMaxX()-40.f,std::clamp(nameCenter.y,bounds.getMinY()+14.f,bounds.getMaxY()-14.f)});
        auto button = mucho::button(ButtonSprite::create("Art"),this,menu_selector(MuchoColorProfile::onMuchoProfile),.32f);
        button->setID("mucho-profile-art-button"); menu->addChild(button); m_mainLayer->addChild(menu,25);
    }
};

class $modify(MuchoColorScore, GJScoreCell) {
    void loadFromScore(GJUserScore* score) {
        if (m_mainLayer) if (auto old = m_mainLayer->getChildByID("mucho-score-avatar")) old->removeFromParent();
        GJScoreCell::loadFromScore(score);
        if (!score) return;
        auto label = mucho::appearance::namedLabel(m_mainLayer, std::string(score->m_userName.c_str()));
        if (label) mucho::appearance::bind(this, label, score->m_accountID,
            mucho::appearance::scoreNameWidth(m_mainLayer,label,m_width),true);
        auto icon = m_mainLayer->getChildByID("player-icon");
        if (!icon) icon = m_mainLayer->getChildByType<SimplePlayer>(0);
        mucho::profile::bindScoreAvatar(m_mainLayer,icon,score->m_accountID,m_height*.48f);
    }
};

class $modify(MuchoColorComment, CommentCell) {
    void loadFromComment(GJComment* comment) {
        CommentCell::loadFromComment(comment);
        if (!comment) return;
        auto label = mucho::appearance::namedLabel(m_mainLayer, std::string(comment->m_userName.c_str()));
        if (!label && comment->m_userScore) label = mucho::appearance::namedLabel(m_mainLayer, std::string(comment->m_userScore->m_userName.c_str()));
        int account = comment->m_accountID > 0 ? comment->m_accountID : comment->m_userScore ? comment->m_userScore->m_accountID : 0;
        if (label) mucho::appearance::bind(this, label, account, m_compactMode ? 115.f : 170.f);
    }
};

