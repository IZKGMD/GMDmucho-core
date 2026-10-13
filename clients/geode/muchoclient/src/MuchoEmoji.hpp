#pragma once
#include "MuchoEmojiRules.hpp"
namespace mucho::emoji {
    std::string shortenText(std::string_view text,size_t limit);
    void showPicker();
}
