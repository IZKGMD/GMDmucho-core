#pragma once

#include <cstdint>
#include <optional>
#include <string>
#include <string_view>

namespace mucho::appearance {
    inline std::string normalizedColor(std::string_view value) {
        if (value.starts_with('#')) value.remove_prefix(1);
        if (value.size() != 6) return {};
        std::string result;
        for (char c : value) {
            if (c >= 'a' && c <= 'f') c = static_cast<char>(c - 'a' + 'A');
            if (!((c >= '0' && c <= '9') || (c >= 'A' && c <= 'F'))) return {};
            result += c;
        }
        return result;
    }

    inline std::optional<uint32_t> parsedColor(std::string_view value) {
        auto hex = normalizedColor(value);
        if (hex.empty()) return std::nullopt;
        uint32_t result = 0;
        for (char c : hex) result = result * 16 + (c <= '9' ? c - '0' : c - 'A' + 10);
        return result;
    }

    inline bool validOrigin(std::string const& origin) {
        if (!origin.starts_with("https://") || origin.size() < 12 || origin.size() > 255) return false;
        auto host = origin.substr(8);
        for (char c : host) {
            if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                  (c >= '0' && c <= '9') || c == '.' || c == '-' || c == ':')) return false;
        }
        return !host.empty();
    }
}
