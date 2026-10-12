#include "MuchoProfileHeader.hpp"
#include "MuchoMenuAvatar.hpp"

namespace mucho::profile {
namespace { std::vector<WeakRef<Header>> headers; }
    Header* Header::create(std::string url,int account,CCSize size) {
        if (!appearance::validOrigin(url) || account <= 0 || size.width < 100 || size.height < 20) return nullptr;
        auto node = new Header();
        if (!node->init()) { delete node; return nullptr; }
        node->m_origin = std::move(url); node->m_account = account; node->setContentSize(size);
        node->m_banner = Artwork::create(size); node->m_banner->setVisible(false); node->m_banner->dim(100); node->addChild(node->m_banner);
        float side = size.height - 6;
        node->m_avatarFrame = CCLayerColor::create({235,235,245,255},side+2,side+2);
        node->m_avatarFrame->setPosition({5,2}); node->m_avatarFrame->setVisible(false); node->addChild(node->m_avatarFrame,2);
        node->m_avatar = Artwork::create({side,side}); node->m_avatar->setPosition({6,3}); node->m_avatar->setVisible(false); node->addChild(node->m_avatar,3);
        std::erase_if(headers,[](auto const& weak) { return !weak.lock(); }); headers.emplace_back(node);
        node->load(); node->autorelease(); return node;
    }
    void changed(matjson::Value const& player) {
        rememberPublicProfile(player); appearance::remember(player);
        std::erase_if(headers,[](auto const& weak) { return !weak.lock(); });
        for (auto const& weak : headers) if (auto header = weak.lock()) header->saved(player);
        menuAvatarChanged(player);
    }
}
