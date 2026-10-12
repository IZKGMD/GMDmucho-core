#pragma once

#include <algorithm>
#include <cstdint>
#include <string_view>

namespace mucho::quests {
    inline bool validDay(std::string_view day) {
        if (day.size() != 10 || day[4] != '-' || day[7] != '-') return false;
        for (size_t i = 0; i < day.size(); ++i)
            if (i != 4 && i != 7 && (day[i] < '0' || day[i] > '9')) return false;
        int year = (day[0]-'0')*1000 + (day[1]-'0')*100 + (day[2]-'0')*10 + day[3]-'0';
        int month = (day[5]-'0')*10 + day[6]-'0', date = (day[8]-'0')*10 + day[9]-'0';
        if (year < 1970 || month < 1 || month > 12 || date < 1) return false;
        constexpr int days[] = {31,28,31,30,31,30,31,31,30,31,30,31};
        int maximum = days[month-1];
        if (month == 2 && (year%400 == 0 || (year%4 == 0 && year%100 != 0))) ++maximum;
        return date <= maximum;
    }
    inline bool validQuest(std::string_view id, int64_t target, int64_t progress,
                           int64_t reward, bool claimed, bool claimable) {
        return (id == "stars" || id == "moons" || id == "demons") &&
            target > 0 && target <= 1000000 && progress >= 0 && progress <= target &&
            reward >= 0 && reward <= 100000 &&
            claimable == (!claimed && progress >= target);
    }
    inline double progressRatio(int64_t progress, int64_t target) {
        return target > 0 ? std::clamp(static_cast<double>(progress) / target, 0., 1.) : 0.;
    }
    inline int remaining(int seconds, int64_t elapsed) {
        return static_cast<int>(std::max(int64_t{0}, std::min(int64_t{86400},
            static_cast<int64_t>(seconds) - std::max(int64_t{0}, elapsed))));
    }
    inline size_t pageCount(size_t count) { return count == 0 ? 1 : (count+4)/5; }
    inline size_t pageStart(size_t count, size_t page) {
        return std::min(page, pageCount(count)-1)*5;
    }
}
