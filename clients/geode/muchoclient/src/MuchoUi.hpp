#pragma once
#include <Geode/Geode.hpp>

namespace mucho {
    inline CCMenuItemSpriteExtra* button(cocos2d::CCNode* sprite, cocos2d::CCObject* target,
                                         cocos2d::SEL_MenuHandler handler, float scale = 1.f) {
        auto item = CCMenuItemSpriteExtra::create(sprite, target, handler);
        item->setScale(scale);
        // GD's selected/unselected animations restore m_baseScale, not getScale().
        item->m_baseScale = scale;
        return item;
    }
}
