#pragma once
#include <algorithm>
#include <cstdint>
#include <optional>
#include <span>
#include <string_view>

namespace mucho::profile {
    constexpr size_t MAX_UPLOAD_BYTES = 4 * 1024 * 1024;
    constexpr size_t MAX_IMAGE_BYTES = 512 * 1024;
    struct ImageSize { unsigned width, height; bool png; };

    inline bool validVersion(std::string_view version) {
        return version.size() == 64 && std::all_of(version.begin(), version.end(), [](char c) {
            return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f');
        });
    }
    inline bool safeSize(unsigned w, unsigned h) {
        return w >= 16 && h >= 16 && w <= 8192 && h <= 8192 && uint64_t(w) * h <= 12000000;
    }
    // Check dimensions before any image decoder allocates pixel buffers.
    inline std::optional<ImageSize> imageSize(std::span<uint8_t const> data) {
        if (data.size() < 24 || data.size() > MAX_UPLOAD_BYTES) return {};
        auto be16 = [&](size_t p) { return (unsigned(data[p]) << 8) | data[p + 1]; };
        auto be32 = [&](size_t p) { return (unsigned(data[p]) << 24) | (unsigned(data[p + 1]) << 16) | (unsigned(data[p + 2]) << 8) | data[p + 3]; };
        constexpr uint8_t png[] = {137, 80, 78, 71, 13, 10, 26, 10};
        if (std::equal(std::begin(png), std::end(png), data.begin())) {
            if (be32(8) != 13 || data[12] != 'I' || data[13] != 'H' || data[14] != 'D' || data[15] != 'R') return {};
            unsigned w = be32(16), h = be32(20);
            if (safeSize(w, h)) return ImageSize{w, h, true};
            return {};
        }
        if (data[0] != 0xff || data[1] != 0xd8) return {};
        size_t p = 2;
        while (p + 4 <= data.size()) {
            if (data[p++] != 0xff) return {};
            while (p < data.size() && data[p] == 0xff) ++p;
            if (p >= data.size()) return {};
            auto marker = data[p++];
            if (marker == 0xd9 || marker == 0xda || marker == 0x00) return {};
            if (marker == 0x01 || (marker >= 0xd0 && marker <= 0xd7)) continue;
            if (p + 2 > data.size()) return {};
            auto length = be16(p);
            if (length < 2 || length > data.size() - p) return {};
            bool frame = (marker >= 0xc0 && marker <= 0xcf && marker != 0xc4 && marker != 0xc8 && marker != 0xcc);
            if (frame) {
                if (length < 8) return {};
                unsigned h = be16(p + 3), w = be16(p + 5);
                if (safeSize(w, h)) return ImageSize{w, h, false};
                return {};
            }
            p += length;
        }
        return {};
    }
}
