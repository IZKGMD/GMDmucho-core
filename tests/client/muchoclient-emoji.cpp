#include "../../clients/geode/muchoclient/src/MuchoEmojiData.hpp"
#include <array>
#include <cstdlib>
#include <iostream>
#include <random>
#include <set>
using namespace mucho::emoji;
size_t checks=0;
void require(bool v,char const* msg) { ++checks; if(!v){std::cerr<<"FAIL: "<<msg<<'\n';std::exit(1);} }
void utf8(std::string& s,uint32_t c) {
    if(c<128) s+=static_cast<char>(c);
    else if(c<0x800){s+=static_cast<char>(0xc0|(c>>6));s+=static_cast<char>(0x80|(c&63));}
    else if(c<0x10000){s+=static_cast<char>(0xe0|(c>>12));s+=static_cast<char>(0x80|((c>>6)&63));s+=static_cast<char>(0x80|(c&63));}
    else {s+=static_cast<char>(0xf0|(c>>18));s+=static_cast<char>(0x80|((c>>12)&63));s+=static_cast<char>(0x80|((c>>6)&63));s+=static_cast<char>(0x80|(c&63));}
}
void all(uint32_t node,std::string sequence,std::set<int>& seen) {
    auto n=data::Nodes[node];
    if(n.image>=0) {
        auto p=plan(sequence,data::catalog());
        require(p.original==sequence,"original bytes retained");
        require(p.images.size()==1&&p.images[0]==n.image,"all catalog sequences match whole image");
        require(p.spans.size()==1&&p.spans[0].start==0&&p.spans[0].end==sequence.size(),"native line image retains its source span");
        require(p.render==std::string(1,Placeholder),"one native layout slot per sequence");
        seen.insert(n.image);
    }
    for(uint32_t i=0;i<n.count;++i) {
        auto e=data::Edges[n.first+i]; auto s=sequence;utf8(s,e.cp);all(e.next,std::move(s),seen);
    }
}
int main() {
    require(!mucho::emoji::pickerEligible("",false,false,true),"registration empty filters never create an emoji touch delegate");
    require(!mucho::emoji::pickerEligible(" abc",false,false,true),"registration and login content remains native even with permissive filters");
    require(!mucho::emoji::pickerEligible(" abc",true,false,false),"passwords never get emoji controls");
    require(!mucho::emoji::pickerEligible("0123456789",false,true,false),"numeric inputs stay native");
    require(mucho::emoji::pickerEligible(" abc",false,false,false),"comments and chat keep emoji controls");
    auto catalog=data::catalog();
    std::set<int> seen; all(0,"",seen); require(seen.size()==4009,"pinned complete catalog");
    for(auto s:{"Hello! 123 :)","http://example.com/a:b","[Clan] Player","line 1\nline 2"}) {
        auto p=plan(s,catalog);require(p.images.empty()&&p.render==s&&p.original==s,"ASCII unchanged");
    }
    for(std::string s:{"\U0001f600","\u2764\ufe0f","\U0001f44d\U0001f3fd","\U0001f1f7\U0001f1fa", "\U0001f468\u200d\U0001f469\u200d\U0001f467\u200d\U0001f466", "1\ufe0f\u20e3"}) {
        auto p=plan("A "+s+" B",catalog);
        require(p.images.size()==1&&p.render==std::string("A ")+Placeholder+" B","Unicode grapheme fixture");
        require(shorten("A "+s+" rest",s.size()+5,catalog)=="A "+s+"...","truncate at emoji boundary");
    }
    require(plan("\u2764\ufe0e",catalog).images.empty(),"text presentation selector respected");
    require(plan("\U0001f600\u200d\U0001f680",catalog).images.empty(),"unknown ZWJ sequence not split");
    require(cursorIndex("A\U0001f600B",3,catalog)==2,"UTF-16 cursor after astral emoji");
    require(cursorIndex("A\U0001f44d\U0001f3fdB",5,catalog)==2,"UTF-16 cursor after skin-tone sequence");
    require(cursorIndex("\U0001f1f7\U0001f1fa",4,catalog)==1,"flag cursor uses one visible slot");
    require(cursorIndex("abc",99,catalog)==3,"cursor clamped to final slot");
    for(bool units:{false,true}) {
        auto mixed=std::string("A")+"\U0001f44d\U0001f3fd"+" B "+"\U0001f1f7\U0001f1fa";
        for(int slot=0;slot<=6;++slot) {
            auto native=sourceCursor(mixed,slot,catalog,units);
            require(cursorIndex(mixed,native,catalog,units)==slot,"touch and caret indices round-trip across emoji sequences");
        }
        auto point=sourceCursor(mixed,2,catalog,units);
        require(insertAtCursor(mixed,"\U0001f600",point,catalog,units,140)==std::string("A\U0001f44d\U0001f3fd\U0001f600 B \U0001f1f7\U0001f1fa"),"insert into existing emoji text without replacing it");
        require(insertAtCursor("hello","\U0001f600",-1,catalog,units,140)=="hello\U0001f600","append at native end sentinel");
        require(insertAtCursor("hello","\U0001f600",2,catalog,units,140)=="he\U0001f600llo","insert within ordinary text");
        require(insertAtCursor("hello","\U0001f600",2,catalog,units,8)=="hello","reject whole emoji at byte limit");
        require(cursorByte("A\U0001f44d\U0001f3fdB",2,catalog,units)==1,"cursor inside combined emoji snaps to its beginning");
        require(sourceCursor(mixed,-1,catalog,units)==-1,"end sentinel preserved after touch conversion");
    }
    auto lines=plan("hello \U0001f600\nworld \U0001f1f7\U0001f1fa",catalog);
    require(lines.spans.size()==2&&lines.original.substr(lines.spans[1].start,lines.spans[1].end-lines.spans[1].start)=="\U0001f1f7\U0001f1fa","wrapped native lines retain flag sequence");
    auto metric=[](uint32_t,uint32_t cp) {return cp==static_cast<unsigned char>(Placeholder)?
        FontMetric{24,24,24,0,0,0}:FontMetric{12,12,24,0,0,0};};
    for(auto text:{"\U0001f60a\U0001f60a","\U0001f60a\U0001f525","\U0001f525\U0001f60a","\U0001f60a\U0001f525\U0001f600"}) {
        auto pair=plan(text,catalog);auto anchors=markerAnchors(pair.render,24,metric);
        require(anchors.size()==pair.images.size(),"adjacent emoji retain every render anchor independently of native glyphs");
        for(size_t i=1;i<anchors.size();++i)
            require(anchors[i].x-anchors[i-1].x==24&&anchors[i].y==anchors[i-1].y,"adjacent emoji occupy distinct non-overlapping slots");
        auto next=insertAtCursor(text,"\U0001f525",-1,catalog,false,140);
        require(plan(next,catalog).images.size()==pair.images.size()+1,"repeated insertion retains existing adjacent emoji");
    }
    auto wrapped=markerAnchors(std::string(1,Placeholder)+"\n"+Placeholder,24,metric);
    require(wrapped.size()==2&&wrapped[0].x==wrapped[1].x&&wrapped[0].y-wrapped[1].y==24,"emoji line break retains both image anchors");
    auto surrounded=markerAnchors(std::string("AB")+Placeholder+Placeholder+"C",24,metric);
    require(surrounded.size()==2&&surrounded[0].x==36&&surrounded[1].x==60,"adjacent emoji align with surrounding bitmap text");
    std::string large;for(size_t i=0;i<MaxGlyphs+25;++i) large+="\U0001f600";
    require(plan(large,catalog).images.size()==MaxGlyphs,"sprite budget");
    auto p=plan(std::string(MaxBytes,'x')+"\U0001f600",catalog);
    require(p.images.empty(),"oversized labels create no image nodes");
    require(inputBytes("ASCII only",catalog).empty(),"input filter excludes ASCII additions");
    auto bytes=inputBytes("\U0001f44d\U0001f3fd",catalog);
    require(!bytes.empty()&&std::all_of(bytes.begin(),bytes.end(),[](unsigned char c){return c>=128;}),"emoji byte extension only");
    std::mt19937 random(9417);
    for(int i=0;i<15000;++i) {
        std::string s;for(unsigned int j=0,n=random()%96;j<n;++j) s+=static_cast<char>(random()%256);
        auto value=plan(s,catalog);require(value.images.size()<=MaxGlyphs,"fuzz glyph bound");
        for(size_t at=0;at<value.render.size();) {auto r=decode(value.render,at);require(r.valid&&r.bytes>0,"malformed UTF-8 repaired safely");at+=r.bytes;}
    }
    for(std::string bad:{"\xc0\xaf","\xed\xa0\x80","\xf4\x90\x80\x80","\xe2\x82"})
        require(!decode(bad,0).valid,"reject overlong, surrogate, out-of-range, truncated input");
    std::cout<<"MUCHO_EMOJI_RULES_OK checks="<<checks<<" images="<<seen.size()<<'\n';
}
