#include "../../clients/geode/muchoclient/src/MuchoClanRules.hpp"
#include "../../clients/geode/muchoclient/src/MuchoAppearanceRules.hpp"
#include "../../clients/geode/muchoclient/src/MuchoProfileRules.hpp"
#include "../../clients/geode/muchoclient/src/MuchoCropRules.hpp"
#include <cassert>
#include <iostream>
#include <vector>

int main() {
    using namespace mucho::clans;
    using namespace mucho::appearance;
    assert(normalizedColor("#66ccff") == "66CCFF");
    assert(parsedColor("000000").value() == 0);
    assert(parsedColor("FFFFFF").value() == 0xFFFFFF);
    assert(parsedColor("12ABcd").value() == 0x12ABCD);
    for (auto invalid : {"", "FFF", "FFFFFFF", "<cR>", "GGGGGG", "12;345", "FFFFFF\n"}) assert(!parsedColor(invalid));
    assert(validOrigin("https://muchogdps.space"));
    for (auto invalid : {"http://muchogdps.space", "https://a@b.com", "https://a.com/path", "https://a.com?x", "https://a.com#x"}) assert(!validOrigin(invalid));
    assert(mucho::profile::validVersion(std::string(64,'a')));
    for (auto invalid : {std::string(63,'a'),std::string(65,'a'),std::string(64,'A'),std::string(64,'g')}) assert(!mucho::profile::validVersion(invalid));
    assert(mucho::profile::safeSize(4000,3000));
    assert(!mucho::profile::safeSize(4001,3000) && !mucho::profile::safeSize(9000,16) && !mucho::profile::safeSize(15,256));
    std::vector<uint8_t> png = {137,80,78,71,13,10,26,10,0,0,0,13,'I','H','D','R',0,0,1,0,0,0,1,0};
    assert(mucho::profile::imageSize(png)->width == 256 && mucho::profile::imageSize(png)->png);
    png[16] = 0xff; assert(!mucho::profile::imageSize(png));
    png[16] = 0; png[12] = 'x'; assert(!mucho::profile::imageSize(png));
    std::vector<uint8_t> jpeg = {0xff,0xd8,0xff,0xe0,0,4,0,0,0xff,0xc0,0,17,8,0,32,0,64,3,1,1,0,2,1,0,3,1,0,0xff,0xd9};
    auto size = mucho::profile::imageSize(jpeg);
    assert(size && !size->png && size->width == 64 && size->height == 32);
    jpeg[10] = 0xff; assert(!mucho::profile::imageSize(jpeg));
    assert(!mucho::profile::imageSize(std::vector<uint8_t>(30,0)));
    assert(!mucho::profile::imageSize(std::vector<uint8_t>(mucho::profile::MAX_UPLOAD_BYTES+1,0)));
    // Absolute crop landmarks, including portrait rotation and all image edges.
    using mucho::profile::cropRegion;
    auto portrait=cropRegion(900,1200,0,1000,100,0);
    assert(portrait.width==900 && portrait.height==300 && portrait.left==0 && portrait.top==900);
    auto rotated=cropRegion(900,1200,1000,1000,100,90);
    assert(rotated.imageWidth==1200 && rotated.imageHeight==900 && rotated.width==1200 && rotated.height==400 && rotated.top==500);
    auto square=cropRegion(900,1200,1000,0,300,0,256,256);
    assert(square.width==300 && square.height==300 && square.left==600 && square.top==0);
    assert(mucho::profile::cropPosition(-12,100)==0 && mucho::profile::cropPosition(120,100)==1000);
    assert(mucho::profile::cropPosition(50,100)==500 && mucho::profile::cropPosition(0,0)==500);
    assert(mucho::profile::cropZoom(300,100)==300 && mucho::profile::cropZoom(300,600)==100);
    assert(mucho::profile::cropZoom(.3,.1)==300);
    // The displayed 126x42 preview must select the identical source pixels as
    // the server's 960x320 output, not a second, independently rounded crop.
    for (auto [w,h] : {std::pair{900,1200},std::pair{1920,1080},std::pair{4000,3000},std::pair{8192,16}})
        for (int rotation : {0,90,180,270}) for (int zoom : {100,137,200,299,300})
            for (int position : {0,253,500,1000}) {
                auto output=cropRegion(w,h,position,1000-position,zoom,rotation);
                auto preview=cropRegion(w,h,position,1000-position,zoom,rotation,126,42);
                assert(output.width==preview.width && output.height==preview.height && output.left==preview.left && output.top==preview.top);
                assert(output.left>=0 && output.top>=0 && output.width>0 && output.height>0);
                assert(output.left+output.width<=output.imageWidth && output.top+output.height<=output.imageHeight);
                int x=mucho::profile::cropPosition(output.left,output.imageWidth-output.width);
                int y=mucho::profile::cropPosition(output.top,output.imageHeight-output.height);
                auto recovered=cropRegion(w,h,x,y,zoom,rotation);
                assert(std::abs(recovered.left-output.left)<=1 && std::abs(recovered.top-output.top)<=1);
            }
    using mucho::profile::focusedCrop;
    using mucho::profile::movedCrop;
    // Off-centre landmarks used to jump when zoom kept the old slack fraction.
    auto focus=focusedCrop(1200,900,250,600,200,0,300,0);
    auto zoomed=cropRegion(1200,900,focus.x,focus.y,300,0);
    assert(std::abs(zoomed.left+zoomed.width/2-450)<=1);
    assert(std::abs(zoomed.top+zoomed.height/2-520)<=1);
    focus=focusedCrop(1200,900,250,600,200,0,200,90);
    auto turned=cropRegion(1200,900,focus.x,focus.y,200,90);
    assert(std::abs(turned.left+turned.width/2-380)<=1);
    assert(std::abs(turned.top+turned.height/2-450)<=1);
    auto edge=focusedCrop(1200,900,0,0,300,0,100,0);
    auto expanded=cropRegion(1200,900,edge.x,edge.y,100,0);
    assert(expanded.left==0 && expanded.top==0 && expanded.width==1200 && expanded.height==400);
    // Clockwise rotation followed by three more turns must return the same
    // subject for both output shapes, allowing integer API/pixel rounding.
    for (auto [outW,outH]:{std::pair{960,320},std::pair{256,256}}) {
        int x=400,y=600;
        auto start=cropRegion(1200,900,x,y,300,0,outW,outH);
        for (int rotation:{0,90,180,270}) {
            auto p=focusedCrop(1200,900,x,y,300,rotation,300,(rotation+90)%360,outW,outH);
            x=p.x; y=p.y;
        }
        auto end=cropRegion(1200,900,x,y,300,0,outW,outH);
        assert(std::abs(end.left-start.left)<=3 && std::abs(end.top-start.top)<=3);
    }
    // Zooming in cannot lose the current centre, even on extreme panoramas.
    for (auto [w,h]:{std::pair{900,1200},std::pair{4000,3000},std::pair{8192,16}})
        for (int rotation:{0,90,180,270}) for (int position:{0,253,500,1000}) {
            auto before=cropRegion(w,h,position,1000-position,137,rotation);
            auto p=focusedCrop(w,h,position,1000-position,137,rotation,299,rotation);
            auto after=cropRegion(w,h,p.x,p.y,299,rotation);
            double tolerance=std::max(w,h)/2000.0+1;
            assert(std::abs(before.left+before.width/2-after.left-after.width/2)<=tolerance);
            assert(std::abs(before.top+before.height/2-after.top-after.height/2)<=tolerance);
            assert(after.left>=0 && after.top>=0 && after.left+after.width<=after.imageWidth && after.top+after.height<=after.imageHeight);
        }
    // Arrow movement uses source pixels, keeps zoom and clamps each edge.
    auto beforeMove=cropRegion(1200,900,250,600,200,0);
    auto step=movedCrop(beforeMove,3,-3);
    auto afterMove=cropRegion(1200,900,step.x,step.y,200,0);
    assert(afterMove.left==153 && afterMove.top==417 && afterMove.width==600 && afterMove.height==200);
    auto endMove=movedCrop(beforeMove,10000,-10000);
    assert(endMove.x==1000 && endMove.y==0);
    assert(formattedCounter(0) == "0");
    assert(formattedCounter(999) == "999");
    assert(formattedCounter(1000) == "1,000");
    assert(formattedCounter(6000000000ULL) == "6,000,000,000");
    assert(formattedCounter(UINT64_MAX) == "18,446,744,073,709,551,615");
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
