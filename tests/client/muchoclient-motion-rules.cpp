#include "../../clients/geode/muchoclient/src/MuchoMotionRules.hpp"
#include <cstdlib>
#include <iostream>
#include <limits>
#include <set>

static unsigned checks=0;
static void check(bool ok,char const* reason) {
    if(!ok){std::cerr<<"FAIL "<<reason<<'\n';std::exit(1);}++checks;
}
int main(){
    using namespace mucho::motion::rules;
    for(bool enabled:{false,true})for(bool reduced:{false,true})for(bool gameplay:{false,true}){
        auto value=mode(enabled,reduced,gameplay);
        check((value==Mode::Off)==(!enabled||gameplay),"gameplay and disabled settings suppress extra effects");
        check(particles(value)==(value==Mode::Full?8u:0u),"reduced motion has no reward particles");
    }
    check(!available(maxAnimatedNodes)&&available(maxAnimatedNodes-1),"bounded action-node budget");
    for(unsigned i=0;i<10000;++i)check(delay(i)>=0&&delay(i)<=.1441f,"stagger delay cannot grow with list length");
    check(ratio(std::numeric_limits<float>::quiet_NaN())==0,"invalid ratio cannot corrupt node transform");
    check(ratio(-10)==0&&ratio(100)==1,"progress is clamped to track");
    for(int fps:{30,60,120,144,240})for(auto pair:{std::pair<int64_t,int64_t>{0,140},{140,0},{99,100},{999999999999LL,1000000000000LL}}){
        int64_t previous=pair.first;
        std::set<unsigned> repaints;
        for(int frame=0;frame<=fps;++frame){
            float t=static_cast<float>(frame)/fps;
            auto value=counter(pair.first,pair.second,t);
            check(value>=std::min(pair.first,pair.second)&&value<=std::max(pair.first,pair.second),"counter never overshoots");
            check(pair.first<=pair.second?value>=previous:value<=previous,"counter stays monotonic across frame rates");
            check(ease(t)>=0&&ease(t)<=1,"ease stays within layout bounds");
            previous=value; repaints.insert(step(t));
        }
        check(previous==pair.second,"exact final counter at every frame rate");
        check(repaints.size()<=counterSteps+1,"glyph rebuilding is bounded independently of FPS");
    }
    check(counter(-100,2000000000000LL,1)==1000000000000LL,"counter amount bounds");
    std::cout<<"MUCHO_MOTION_RULES_OK checks="<<checks<<'\n';
}
