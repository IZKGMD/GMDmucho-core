#include "MuchoProfileHeader.hpp"

namespace mucho::profile {
namespace { std::vector<WeakRef<Header>> headers; }
    Header* Header::create(std::string url,int account) {
        if (!appearance::validOrigin(url) || account <= 0) return nullptr;
        auto node = new Header();
        if (!node->init()) { delete node; return nullptr; }
        node->m_origin = std::move(url); node->m_account = account; node->setContentSize({350,46});
        node->m_banner = Artwork::create({350,46}); node->m_banner->setVisible(false); node->m_banner->dim(100); node->addChild(node->m_banner);
        node->m_avatar = Artwork::create({36,36}); node->m_avatar->setPosition({6,5}); node->m_avatar->setVisible(false); node->addChild(node->m_avatar,2);
        std::erase_if(headers,[](auto const& weak) { return !weak.lock(); }); headers.emplace_back(node);
        node->load(); node->autorelease(); return node;
    }
    void changed(matjson::Value const& player) {
        clearCache(); appearance::remember(player);
        std::erase_if(headers,[](auto const& weak) { return !weak.lock(); });
        for (auto const& weak : headers) if (auto header = weak.lock()) header->saved(player);
    }
}
