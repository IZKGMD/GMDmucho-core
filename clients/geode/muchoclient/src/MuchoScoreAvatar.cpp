#include "MuchoScoreAvatar.hpp"
#include "MuchoProfileArtwork.hpp"
#include <map>

namespace mucho::profile {
namespace {
    using Clock = std::chrono::steady_clock;
    struct AvatarInfo { std::string version; Clock::time_point expires; };
    std::map<int, AvatarInfo> metadata;
    std::string metadataOrigin;

    void syncMetadata(std::string const& url) {
        if (metadataOrigin != url) { metadata.clear(); metadataOrigin = url; }
    }

    class ScoreAvatar final : public CCNode {
        std::string m_origin, m_version;
        int m_account = 0;
        unsigned m_generation = 0;
        bool m_closed = false, m_iconVisible = true;
        WeakRef<CCNode> m_icon;
        Artwork* m_artwork = nullptr;
        async::TaskHolder<web::WebResponse> m_request;

        bool current() const { return !m_closed && origin() == m_origin && m_icon.lock(); }
        void fallback() {
            m_artwork->setVisible(false);
            if (auto icon = m_icon.lock()) icon->setVisible(m_iconVisible);
        }
        void apply(std::string const& version) {
            if (!current()) return;
            fallback(); m_version = version;
            if (validVersion(version)) m_artwork->stored(m_origin, m_account, "avatar", version);
            else m_artwork->empty();
        }
        void load() {
            if (!current() || !appearance::validOrigin(m_origin)) return;
            syncMetadata(m_origin);
            auto found = metadata.find(m_account);
            if (found != metadata.end() && found->second.expires > Clock::now()) {
                apply(found->second.version); return;
            }
            auto generation = ++m_generation;
            m_request.spawn("MuchoCore leaderboard avatar", web::WebRequest().timeout(std::chrono::seconds(8))
                .followRedirects(false).header("Content-Type", "application/x-www-form-urlencoded")
                .bodyString("accountID=" + std::to_string(m_account)).post(m_origin + "/api/profile/get"),
                [this, generation](web::WebResponse response) {
                    if (generation != m_generation || !current() || !response.ok() ||
                        response.string().unwrapOr("").size() > 16384) return;
                    auto json = response.json();
                    if (!json || !json.unwrap()["ok"].asBool().unwrapOr(false)) return;
                    auto player = json.unwrap()["data"];
                    if (player["account_id"].asInt().unwrapOr(0) != m_account) return;
                    auto version = player["media"]["avatar"]["version"].asString().unwrapOr("");
                    if (!version.empty() && !validVersion(version)) return;
                    syncMetadata(m_origin);
                    if (metadata.size() >= 128 && !metadata.contains(m_account)) {
                        auto oldest = std::min_element(metadata.begin(), metadata.end(), [](auto const& a, auto const& b) {
                            return a.second.expires < b.second.expires;
                        });
                        metadata.erase(oldest);
                    }
                    metadata[m_account] = {version, Clock::now() + std::chrono::seconds(60)};
                    apply(version);
                });
        }
        void onEnter() override { CCNode::onEnter(); m_closed = false; load(); }
        void onExit() override {
            m_closed = true; ++m_generation; m_request.cancel(); fallback();
            m_artwork->empty(); m_version.clear(); CCNode::onExit();
        }
    public:
        static ScoreAvatar* create(CCNode* icon, int account, float side) {
            auto node = new ScoreAvatar();
            if (!node->init()) { delete node; return nullptr; }
            node->setID("mucho-score-avatar"); node->setContentSize({side, side});
            node->m_origin = origin(); node->m_account = account; node->m_icon = icon;
            node->m_iconVisible = icon->isVisible();
            node->m_artwork = Artwork::create({side, side}); node->m_artwork->setVisible(false);
            node->addChild(node->m_artwork);
            auto weak = WeakRef(node);
            node->m_artwork->onLoaded([weak](CCTexture2D*) {
                auto self = weak.lock();
                if (!self || !self->current() || !self->m_artwork->ready()) return;
                if (auto icon = self->m_icon.lock()) icon->setVisible(false);
                self->m_artwork->setVisible(true);
            });
            node->autorelease(); return node;
        }
    };
}
    void bindScoreAvatar(CCNode* root, CCNode* icon, int account, float side) {
        if (!root || !icon || account <= 0 || !appearance::validOrigin(origin()) || !std::isfinite(side)) return;
        side = std::clamp(side, 20.f, 32.f);
        auto center = root->convertToNodeSpace(icon->convertToWorldSpace(icon->getContentSize() / 2));
        if (auto avatar = ScoreAvatar::create(icon, account, side)) {
            avatar->setPosition({center.x - side / 2, center.y - side / 2}); root->addChild(avatar, 20);
        }
    }
}
