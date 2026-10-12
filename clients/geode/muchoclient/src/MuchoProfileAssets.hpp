#pragma once
#include <Geode/Geode.hpp>
#include <functional>
namespace mucho::profile {
    using ValidAsset = std::function<bool()>;
    using ProfileLoaded = std::function<void(matjson::Value const&)>;
    using TextureLoaded = std::function<void(cocos2d::CCTexture2D*)>;
    bool publicProfile(std::string url,int account,ValidAsset valid,ProfileLoaded loaded);
    void rememberPublicProfile(matjson::Value const& player);
    bool storedTexture(std::string url,int account,std::string kind,std::string version,ValidAsset valid,TextureLoaded loaded);
}
