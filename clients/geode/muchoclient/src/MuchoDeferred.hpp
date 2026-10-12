#pragma once
#include <functional>
namespace mucho::performance {
    bool defer(std::function<bool()> valid, std::function<void()> run);
    bool cached(std::function<bool()> valid, std::function<void()> run);
}
