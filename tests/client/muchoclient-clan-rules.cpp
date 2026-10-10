#include "../../clients/geode/muchoclient/src/MuchoClanRules.hpp"
#include <cassert>
#include <iostream>

int main() {
    using namespace mucho::clans;
    assert(positiveId("2147483647") == 2147483647);
    for (auto invalid : {"", "-1", "+2", "12x", " 42", "2147483648", "999999999999999999999"}) assert(positiveId(invalid) == 0);
    assert(positiveId("00042") == 42);
    assert(normalizedName("  Mucho\t  Players \n") == "Mucho Players");
    assert(normalizedName("A").empty());
    assert(normalizedName("_Clan").empty());
    assert(normalizedName("Mucho&Clans").empty());
    assert(normalizedName("ABCDEFGHIJKLMNOPQRSTUVWXYZ").empty());
    assert(normalizedTag(" gd123 ") == "GD123");
    for (auto invalid : {"G", "1234567", "GD PS", "AB&", ""}) assert(normalizedTag(invalid).empty());
    assert(encoded("A&B= +%\n") == "A%26B%3D%20%2B%25%0A");
    assert(encoded("safe_123.~-") == "safe_123.~-");
    assert(canManage("owner") && canManage("officer") && !canManage("member") && !canManage("unknown"));
    for (auto actor : {"owner", "officer", "member", "unknown"}) {
        for (auto target : {"owner", "officer", "member", "unknown"}) {
            assert(!canModerate(actor, 10, target, 10));
            assert(!canModerate(actor, 0, target, 20));
            bool expected = std::string_view(actor) == "owner" ?
                std::string_view(target) == "member" || std::string_view(target) == "officer" :
                std::string_view(actor) == "officer" && std::string_view(target) == "member";
            assert(canModerate(actor, 10, target, 20) == expected);
        }
    }
    std::cout << "MUCHOCLIENT_CLAN_RULES_OK\n";
}
