#pragma once
#include "MuchoProfilePopup.hpp"

namespace mucho::profile {
    class Header final : public CCNode {
        std::string m_origin;
        int m_account = 0;
        Artwork *m_avatar = nullptr, *m_banner = nullptr;
        CCLayerColor* m_avatarFrame = nullptr;
        unsigned m_generation = 0;
    public:
        void saved(matjson::Value const& player) { ++m_generation; apply(player); }
        void apply(matjson::Value const& player) {
            if (player["account_id"].asInt().unwrapOr(0) != m_account || origin() != m_origin) return;
            auto avatar = version(player,"avatar"), banner = version(player,"banner");
            m_avatarFrame->setVisible(validVersion(avatar));
            m_avatar->setVisible(validVersion(avatar)); m_banner->setVisible(validVersion(banner));
            m_avatar->stored(m_origin,m_account,"avatar",avatar); m_banner->stored(m_origin,m_account,"banner",banner);
        }
        void load() {
            auto weak = WeakRef(this); auto generation = ++m_generation;
            publicProfile(m_origin,m_account,[weak,generation] {
                auto self = weak.lock(); return self && self->m_generation == generation && origin() == self->m_origin;
            },[weak](matjson::Value const& player) { if (auto self = weak.lock(); self && player.isObject()) self->apply(player); });
        }
        static Header* create(std::string url,int account,CCSize size);
    };
}
