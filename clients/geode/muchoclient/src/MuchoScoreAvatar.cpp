#include "MuchoScoreAvatar.hpp"
#include "MuchoProfileArtwork.hpp"

namespace mucho::profile {
namespace {

    class ScoreAvatar final : public CCNode {
        std::string m_origin, m_version;
        int m_account = 0;
        unsigned m_generation = 0;
        bool m_closed = false, m_iconVisible = true;
        WeakRef<CCNode> m_icon;
        Artwork* m_artwork = nullptr;

        bool current() const { return !m_closed && origin() == m_origin && m_icon.lock(); }
        void fallback() {
            m_artwork->setVisible(false);
            if (auto icon = m_icon.lock()) icon->setVisible(m_iconVisible);
        }
        void apply(std::string const& version) {
            if (!current()) return;
            if (version == m_version && m_artwork->ready()) {
                if (auto icon = m_icon.lock()) icon->setVisible(false);
                m_artwork->setVisible(true); return;
            }
            fallback(); m_version = version;
            if (validVersion(version)) m_artwork->stored(m_origin, m_account, "avatar", version);
            else m_artwork->empty();
        }
        void load() {
            if (!current() || !appearance::validOrigin(m_origin)) return;
            auto generation = ++m_generation;
            auto weak = WeakRef(this);
            publicProfile(m_origin,m_account,[weak,generation] {
                auto self = weak.lock(); return self && generation == self->m_generation && self->current();
            },[weak](matjson::Value const& player) {
                    auto self = weak.lock(); if (!self || !player.isObject()) return;
                    if (player["account_id"].asInt().unwrapOr(0) != self->m_account) return;
                    auto version = player["media"]["avatar"]["version"].asString().unwrapOr("");
                    if (!version.empty() && !validVersion(version)) return;
                    self->apply(version);
                });
        }
        void onEnter() override { CCNode::onEnter(); m_closed = false; load(); }
        void onExit() override {
            m_closed = true; ++m_generation; fallback();
            m_artwork->suspend(); CCNode::onExit();
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
