#pragma once
#include "MuchoProfileArtwork.hpp"

namespace mucho::profile {
    void updateMenuAvatar(cocos2d::CCNode* menu,float dt);
    void menuAvatarChanged(matjson::Value const& player);
}
