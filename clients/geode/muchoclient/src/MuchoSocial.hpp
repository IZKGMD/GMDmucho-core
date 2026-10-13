#pragma once
#include <Geode/Geode.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/ui/TextInput.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoClanRules.hpp"
#include "MuchoUi.hpp"
#include "MuchoEmoji.hpp"
namespace mucho::social {
void activity(std::string state,int level=0);
void show(int account=0);
}
