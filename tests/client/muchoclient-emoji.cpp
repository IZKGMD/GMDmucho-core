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
        require(p.render==std::string(1,Placeholder),"one native layout slot per sequence");
        seen.insert(n.image);
    }
    for(uint32_t i=0;i<n.count;++i) {
        auto e=data::Edges[n.first+i]; auto s=sequence;utf8(s,e.cp);all(e.next,std::move(s),seen);
    }
}
int main() {
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
