#include "MuchoProfileArtwork.hpp"
#include <map>

namespace mucho::profile {
namespace {
    struct Entry { std::vector<uint8_t> bytes; uint64_t used; };
    std::map<std::string, Entry> images;
    std::string cacheOrigin;
    size_t total = 0;
    uint64_t sequence = 0;
    void sync() {
        auto url = origin();
        if (url != cacheOrigin) { images.clear(); total = 0; cacheOrigin = std::move(url); }
    }
}
    void clearCache() { images.clear(); total = 0; }
    std::optional<std::vector<uint8_t>> cached(std::string const& key) {
        sync(); auto found = images.find(key);
        if (found == images.end()) return {};
        found->second.used = ++sequence; return found->second.bytes;
    }
    void cache(std::string key, std::vector<uint8_t> bytes) {
        sync(); if (bytes.size() > MAX_IMAGE_BYTES) return;
        if (auto found = images.find(key); found != images.end()) { total -= found->second.bytes.size(); images.erase(found); }
        while (!images.empty() && (total + bytes.size() > 4 * 1024 * 1024 || images.size() >= 24)) {
            auto oldest = std::min_element(images.begin(), images.end(), [](auto const& a, auto const& b) { return a.second.used < b.second.used; });
            total -= oldest->second.bytes.size(); images.erase(oldest);
        }
        total += bytes.size(); images.emplace(std::move(key), Entry{std::move(bytes), ++sequence});
    }
}
