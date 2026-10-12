#include "../../clients/geode/muchoclient/src/MuchoDailyQuestRules.hpp"
#include <cstdlib>
#include <iostream>

static int checks = 0;
static void check(bool result, char const* label) {
    if (!result) { std::cerr << "FAIL " << label << '\n'; std::exit(1); }
    ++checks;
}
int main() {
    using namespace mucho::quests;
    check(validDay("2026-10-12"),"valid quest day");
    check(validDay("2024-02-29"),"leap day");
    check(!validDay("2026-02-29"),"invalid leap day");
    check(!validDay("2026-04-31"),"invalid month day");
    check(!validDay("2026-10-12&questID=demons"),"day injection rejected");
    check(!validDay("2026-00-01"),"zero month rejected");
    check(!validDay("0000-01-01"),"pre-epoch day rejected");
    check(validQuest("stars",10,0,25,false,false),"in-progress quest");
    check(validQuest("moons",10,10,40,false,true),"completed quest");
    check(validQuest("demons",1,1,75,true,false),"claimed quest");
    check(!validQuest("fake",1,1,1,false,true),"unknown quest rejected");
    check(!validQuest("stars",0,0,25,false,true),"zero target rejected");
    check(!validQuest("stars",10,11,25,false,true),"over-target progress rejected");
    check(!validQuest("stars",10,9,25,false,true),"premature claimability rejected");
    check(!validQuest("stars",10,10,25,true,true),"claimed cannot remain claimable");
    check(!validQuest("stars",10,10,-1,false,true),"negative reward rejected");
    check(progressRatio(5,10)==.5,"progress ratio");
    check(progressRatio(15,10)==1.,"ratio capped");
    check(progressRatio(-1,10)==0.,"negative ratio capped");
    check(progressRatio(10,0)==0.,"zero target ratio safe");
    check(remaining(3600,60)==3540,"server timer counts down");
    check(remaining(60,100)==0,"expired timer cannot become negative");
    check(remaining(100, -100)==100,"negative elapsed cannot extend expiry");
    check(pageCount(0)==1 && pageCount(5)==1 && pageCount(6)==2 && pageCount(51)==11,"all plugin pages reachable");
    for (size_t count = 1; count <= 64; ++count) {
        size_t seen = 0;
        for (size_t page = 0; page < pageCount(count); ++page) {
            size_t start = pageStart(count,page), end = std::min(count,start+5);
            check(start==seen,"pagination has no omitted or duplicated module"); seen=end;
        }
        check(seen==count,"last module reachable");
    }
    std::cout << "MUCHO_DAILY_QUEST_RULES_OK checks=" << checks << '\n';
}
