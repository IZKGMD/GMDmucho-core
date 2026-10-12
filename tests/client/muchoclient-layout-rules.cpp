#include "../../clients/geode/muchoclient/src/MuchoLayoutRules.hpp"
#include <cstdlib>
#include <iostream>

static int checks = 0;
static void check(bool value, char const* reason) {
    if (!value) { std::cerr << "FAIL " << reason << '\n'; std::exit(1); }
    ++checks;
}
int main() {
    using namespace mucho::layout;
    using L = DailyQuests;
    // Includes the 320-unit landscape viewport from the reported screenshots.
    for (float width : {120.f,240.f,320.f,420.f,480.f,640.f,854.f,1280.f}) {
        for (float height : {100.f,160.f,240.f,256.f,300.f,320.f,480.f,720.f}) {
            float scale = popupScale(width,height,L::width,L::height);
            check(scale > 0 && scale <= 1,"popup scale is finite and never enlarges");
            check((width-L::width*scale)/2-18*scale >= 0,"panel and close button fit horizontally");
            check((height-L::height*scale)/2-18*scale >= 0,"panel and close button fit vertically");
        }
    }
    for (unsigned row = 0; row < 3; ++row) {
        float bottom = L::cardBottom(row), top = bottom+L::cardHeight;
        check(bottom > L::refreshY+14,"quest row clears refresh hit area");
        check(top < L::resetY-6,"quest row clears reset caption");
        check(L::cardLeft > 0 && L::cardLeft+L::cardWidth < L::width,"cards stay within panel");
        check(L::textX+L::textWidth/2 < L::buttonX-L::buttonWidth/2,"quest text clears claim column");
        check(L::buttonX+L::buttonWidth/2 < L::cardLeft+L::cardWidth,"claim button stays in card");
        if (row < 2) check(L::cardBottom(row+1)+L::cardHeight < bottom,"quest rows never overlap");
    }
    check(popupScale(640,320,L::width,L::height) < 1,"reported landscape screen has reserved close-button space");
    check(popupScale(640,480,L::width,L::height) == 1,"larger screens keep normal text size");
    for (float oldWidth : {20.f,60.f,100.f,180.f}) for (float newWidth : {10.f,40.f,90.f,150.f}) {
        for (float anchor : {0.f,.5f,1.f}) for (float scale : {.5f,1.f,1.5f}) {
            float originalLeft = 80.f-oldWidth*anchor*scale;
            float x = resizedButtonX(80.f,oldWidth,newWidth,anchor,scale);
            check(std::abs(x-newWidth*anchor*scale-originalLeft) < .001f,"decorating a name preserves button left edge");
        }
    }
    for (float left : {0.f,27.f,60.f,100.f}) for (float stat : {160.f,200.f,290.f,400.f}) {
        check(left+nameWidth(left,stat) <= stat-5.99f,"nickname and clan prefix stop before statistic");
    }
    std::cout << "MUCHO_LAYOUT_RULES_OK checks=" << checks << '\n';
}
