#pragma once

#include <algorithm>
#include <climits>
#include <cstdint>
#include <string>
#include <string_view>

namespace mucho::clans {
    inline std::string formattedCounter(uint64_t value) {
        auto text = std::to_string(value);
        for (int i = static_cast<int>(text.size()) - 3; i > 0; i -= 3) text.insert(i, ",");
        return text;
    }

    inline int positiveId(std::string_view value) {
        if (value.empty()) return 0;
        int result = 0;
        for (unsigned char c : value) {
            if (c < '0' || c > '9' || result > (INT_MAX - (c - '0')) / 10) return 0;
            result = result * 10 + c - '0';
        }
        return result;
    }

    inline bool canManage(std::string_view role) {
        return role == "owner" || role == "officer";
    }

    inline bool canModerate(std::string_view actorRole, int actorId,
                            std::string_view targetRole, int targetId) {
        if (actorId <= 0 || targetId <= 0 || actorId == targetId || targetRole == "owner") return false;
        if (targetRole != "member" && targetRole != "officer") return false;
        return actorRole == "owner" || (actorRole == "officer" && targetRole == "member");
    }

    inline std::string normalizedName(std::string_view value) {
        std::string result;
        bool space = false;
        for (unsigned char c : value) {
            if (c == ' ' || c == '\t' || c == '\r' || c == '\n') {
                if (!result.empty()) space = true;
                continue;
            }
            if (space) result += ' ';
            space = false;
            result += static_cast<char>(c);
        }
        if (result.size() < 2 || result.size() > 24) return {};
        auto alnum = [](unsigned char c) {
            return (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9');
        };
        if (!alnum(result.front())) return {};
        for (unsigned char c : result) if (!alnum(c) && c != ' ' && c != '_' && c != '.' && c != '-') return {};
        return result;
    }

    inline std::string normalizedTag(std::string_view value) {
        size_t first = value.find_first_not_of(" \t\r\n");
        if (first == std::string_view::npos) return {};
        size_t last = value.find_last_not_of(" \t\r\n");
        std::string result(value.substr(first, last - first + 1));
        if (result.size() < 2 || result.size() > 6) return {};
        for (auto& c : result) {
            if (c >= 'a' && c <= 'z') c = static_cast<char>(c - 'a' + 'A');
            if (!((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9'))) return {};
        }
        return result;
    }

    inline std::string encoded(std::string_view value) {
        static constexpr char HEX[] = "0123456789ABCDEF";
        std::string out;
        for (unsigned char c : value) {
            if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') out += static_cast<char>(c);
            else { out += '%'; out += HEX[c >> 4]; out += HEX[c & 15]; }
        }
        return out;
    }
}
