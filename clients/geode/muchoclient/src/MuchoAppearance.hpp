#pragma once

#include <Geode/Geode.hpp>
#include "MuchoAppearanceRules.hpp"
#include "MuchoClanRules.hpp"

namespace mucho::appearance {
    inline cocos2d::ccColor3B rgb(std::string const& hex, uint32_t fallback = 0xFFFFFF) {
        auto value = parsedColor(hex).value_or(fallback);
        return {static_cast<GLubyte>(value >> 16), static_cast<GLubyte>(value >> 8), static_cast<GLubyte>(value)};
    }

    inline void paint(cocos2d::CCLabelBMFont* label, std::string const& name,
                      std::string const& tag, std::string const& nicknameColor,
                      std::string const& prefixColor, float width, float scale) {
        std::string prefix = tag.empty() ? "" : "[" + tag + "] ";
        label->setString((prefix + name).c_str());
        if (!tag.empty() || !nicknameColor.empty()) label->setFntFile("bigFont.fnt");
        label->setColor(rgb(nicknameColor));
        for (size_t i = 0; i < prefix.size(); ++i) {
            if (auto glyph = geode::typeinfo_cast<cocos2d::CCSprite*>(label->getChildByTag(static_cast<int>(i))))
                glyph->setColor(rgb(prefixColor, 0xFFD166));
        }
        label->limitLabelWidth(width, scale, 0.f);
    }

    // Public colors are loaded in bounded batches, never with a player's credentials.
    void bind(cocos2d::CCNode* root, cocos2d::CCLabelBMFont* label, int account, float width);
    void remember(matjson::Value const& player);
    void invalidate();
}
