#pragma once
#include <algorithm>
#include <cmath>

namespace mucho::layout {
    inline float popupScale(float windowWidth, float windowHeight, float width, float height) {
        if (!std::isfinite(windowWidth) || !std::isfinite(windowHeight) ||
            !std::isfinite(width) || !std::isfinite(height) || width <= 0 || height <= 0) return 1.f;
        return std::clamp(std::min((windowWidth - 40.f) / width, (windowHeight - 40.f) / height), .01f, 1.f);
    }

    inline float nameWidth(float left, float right) { return std::max(1.f, right - left - 6.f); }
    inline float resizedButtonX(float x, float oldWidth, float newWidth, float anchor, float scale) {
        return x + (newWidth-oldWidth)*anchor*scale;
    }

    struct DailyQuests {
        static constexpr float width = 420.f, height = 300.f;
        static constexpr float cardLeft = 14.f, cardWidth = 392.f, cardHeight = 54.f;
        static constexpr float textX = 150.f, textWidth = 246.f;
        static constexpr float buttonX = 346.f, buttonWidth = 100.f;
        static constexpr float xpY = 253.f, resetY = 234.f, refreshY = 28.f, noticeY = 10.f;
        static constexpr float cardBottom(unsigned index) { return 172.f - index * 60.f; }
    };
}
