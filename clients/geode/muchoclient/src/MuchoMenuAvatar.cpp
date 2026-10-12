#include "MuchoMenuAvatar.hpp"
#include <array>

namespace mucho::profile {
namespace {
    class MenuAvatar final : public CCNode {
        std::string m_origin,m_version;
        int m_account=0;
        unsigned m_generation=0;
        bool m_busy=false;
        float m_refresh=0;
        CCClippingNode* m_circle=nullptr;
        Artwork* m_avatar=nullptr;

        static int account() {
            auto manager=GJAccountManager::sharedState();
            return manager?manager->m_accountID:0;
        }
        bool session() const { return m_account>0 && account()==m_account && origin()==m_origin; }
        void apply(matjson::Value const& player) {
            if (!session() || player["account_id"].asInt().unwrapOr(0)!=m_account) return;
            auto version=player["media"]["avatar"]["version"].asString().unwrapOr("");
            if (version==m_version && (m_avatar->ready() || m_avatar->loading())) {
                m_circle->setVisible(m_avatar->ready()); return;
            }
            m_version=std::move(version); m_circle->setVisible(false);
            if (validVersion(m_version)) m_avatar->stored(m_origin,m_account,"avatar",m_version);
            else m_avatar->empty();
        }
        void load() {
            m_busy=true; auto generation=++m_generation;
            auto weak = WeakRef(this);
            if (!publicProfile(m_origin,m_account,[weak,generation] {
                auto self = weak.lock(); return self && self->m_generation == generation && self->session();
            },[weak](matjson::Value const& player) {
                auto self = weak.lock(); if (!self) return;
                self->m_busy = false; self->m_refresh = player.isObject() ? 60 : 30;
                if (player.isObject()) self->apply(player);
            })) { m_busy = false; m_refresh = 30; }
        }
    public:
        void poll(float dt) {
            auto url=origin(); int active=account();
            if (active!=m_account || url!=m_origin) {
                ++m_generation; m_busy=false; m_refresh=0;
                m_circle->setVisible(false); m_avatar->empty(); m_version.clear();
                m_account=active; m_origin=std::move(url);
            }
            if (m_account<=0 || !appearance::validOrigin(m_origin)) return;
            m_refresh=std::max(0.f,m_refresh-dt);
            if (!m_busy && m_refresh<=0) load();
        }
        void saved(matjson::Value const& player) {
            if (!session() || player["account_id"].asInt().unwrapOr(0)!=m_account) return;
            ++m_generation; m_busy=false; m_refresh=60; apply(player);
        }
        void onExit() override {
            ++m_generation; m_busy=false; m_refresh=0;
            m_circle->setVisible(false); m_avatar->suspend();
            CCNode::onExit();
        }
        static MenuAvatar* create(CCSize normalSize) {
            float side=std::min(normalSize.width,normalSize.height)*.8f;
            if (side<12 || !std::isfinite(side)) return nullptr;
            auto node=new MenuAvatar();
            if (!node->init()) { delete node; return nullptr; }
            node->setID("menu-avatar"_spr); node->setContentSize({side,side});
            node->setPosition({(normalSize.width-side)/2,(normalSize.height-side)/2});
            // Keep the native circle's rim. Polygon geometry makes the stencil
            // circular without depending on alpha-test support or new textures.
            auto stencil=CCDrawNode::create(); std::array<CCPoint,64> points;
            for (size_t i=0;i<points.size();++i) {
                double angle=i*6.283185307179586/points.size();
                points[i]=CCPoint{side/2+static_cast<float>(std::cos(angle))*side/2,
                    side/2+static_cast<float>(std::sin(angle))*side/2};
            }
            stencil->drawPolygon(points.data(),static_cast<unsigned>(points.size()),{1,1,1,1},0,{0,0,0,0});
            node->m_circle=CCClippingNode::create(stencil); node->m_circle->setVisible(false); node->addChild(node->m_circle);
            node->m_avatar=Artwork::create({side,side}); node->m_circle->addChild(node->m_avatar);
            auto weak=WeakRef(node);
            node->m_avatar->onLoaded([weak](CCTexture2D*) {
                if (auto self=weak.lock()) self->m_circle->setVisible(self->session() && self->m_avatar->ready());
            });
            node->autorelease(); return node;
        }
    };
    std::vector<WeakRef<MenuAvatar>> avatars;
}
    void updateMenuAvatar(CCNode* menu,float dt) {
        auto button=typeinfo_cast<CCMenuItemSpriteExtra*>(menu->getChildByIDRecursive("profile-button"));
        if (!button) return;
        auto image=button->getNormalImage(); if (!image) return;
        auto avatar=typeinfo_cast<MenuAvatar*>(image->getChildByID("menu-avatar"_spr));
        if (!avatar) {
            avatar=MenuAvatar::create(image->getContentSize()); if (!avatar) return;
            // Attach to the existing display sprite: preserve the native action,
            // hit area, position, scale and press animation. It remains the fallback.
            image->addChild(avatar,10);
            std::erase_if(avatars,[](auto const& weak) { return !weak.lock(); }); avatars.emplace_back(avatar);
        }
        avatar->poll(dt);
    }
    void menuAvatarChanged(matjson::Value const& player) {
        std::erase_if(avatars,[](auto const& weak) { return !weak.lock(); });
        for (auto const& weak:avatars) if (auto avatar=weak.lock()) avatar->saved(player);
    }
}
