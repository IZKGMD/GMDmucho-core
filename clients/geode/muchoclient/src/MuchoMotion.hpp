#pragma once
#include <Geode/Geode.hpp>
#include <Geode/ui/Popup.hpp>
#include <functional>
#include <string>
#include "MuchoMotionRules.hpp"

namespace mucho::motion {
    rules::Mode mode();
    void markButton(CCMenuItemSpriteExtra* item);
    void panel(cocos2d::CCNode* node);
    void reveal(cocos2d::CCNode* node, unsigned index = 0);
    void appear(cocos2d::CCNode* node);
    void feedback(cocos2d::CCLabelBMFont* label);
    void progress(cocos2d::CCNode* fill, float from, float to);
    void number(cocos2d::CCLabelBMFont* label, int64_t from, int64_t to, float width, float scale,
                std::string prefix = {}, std::string suffix = {}, bool compact = false);
    int64_t numberValue(cocos2d::CCLabelBMFont* label, int64_t fallback);
    void reward(cocos2d::CCNode* parent, cocos2d::CCPoint at, int64_t xp);
    void loading(cocos2d::CCNode* parent, bool active, cocos2d::CCPoint at);
    bool dismiss(geode::Popup* popup, cocos2d::CCNode* panel, std::function<void()> done);
}
