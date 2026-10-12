#pragma once
#include <algorithm>
#include <cmath>
#include <cstdint>

namespace mucho::motion::rules {
    enum class Mode { Off, Reduced, Full };
    inline Mode mode(bool animations, bool reduced, bool gameplay) {
        return !animations || gameplay ? Mode::Off : reduced ? Mode::Reduced : Mode::Full;
    }
    constexpr unsigned maxAnimatedNodes = 48, maxParticles = 8, counterSteps = 12;
    inline bool available(unsigned active) { return active < maxAnimatedNodes; }
    inline float ratio(float value) { return std::isfinite(value) ? std::clamp(value,0.f,1.f) : 0.f; }
    inline float ease(float time) { float t = ratio(time); return 1.f-(1.f-t)*(1.f-t)*(1.f-t); }
    inline unsigned step(float time) { return std::min(counterSteps,static_cast<unsigned>(ratio(time)*counterSteps)); }
    inline int64_t counter(int64_t from, int64_t to, float time) {
        from = std::clamp(from,int64_t{0},int64_t{1000000000000LL});
        to = std::clamp(to,int64_t{0},int64_t{1000000000000LL});
        if (time >= 1.f) return to;
        return from + static_cast<int64_t>(std::llround(static_cast<double>(to-from)*ease(time)));
    }
    inline float delay(unsigned index) { return std::min(index,8u)*.018f; }
    inline unsigned particles(Mode mode) { return mode == Mode::Full ? maxParticles : 0; }
}
