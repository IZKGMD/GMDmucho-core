#pragma once
#include <algorithm>
#include <cstdint>
#include <span>
#include <string>
#include <string_view>
#include <vector>

namespace mucho::emoji {
    // Render-only marker. The original UTF-8 text is retained by the label.
    inline constexpr char Placeholder = '\x1f';
    inline constexpr size_t MaxBytes = 16384, MaxGlyphs = 128, MaxSequence = 24;
    struct TrieNode { uint32_t first; uint16_t count; int16_t image; };
    struct TrieEdge { uint32_t cp; uint32_t next; };
    struct Catalog { std::span<TrieNode const> nodes; std::span<TrieEdge const> edges; };
    struct Rune { uint32_t cp; size_t bytes; bool valid; };
    inline Rune decode(std::string_view s, size_t at) {
        if (at >= s.size()) return {0,0,false};
        auto a=static_cast<uint8_t>(s[at]);
        if (a < 0x80) return {a,1,true};
        size_t n=a>=0xc2&&a<=0xdf?2:a>=0xe0&&a<=0xef?3:a>=0xf0&&a<=0xf4?4:0;
        if (!n || n>s.size()-at) return {'?',1,false};
        uint32_t cp=a & (n==2?31:n==3?15:7);
        for(size_t i=1;i<n;++i) {
            auto b=static_cast<uint8_t>(s[at+i]);
            if((b&0xc0)!=0x80) return {'?',1,false};
            cp=(cp<<6)|(b&63);
        }
        if(cp<(n==2?0x80u:n==3?0x800u:0x10000u)||cp>0x10ffff||(cp>=0xd800&&cp<=0xdfff)) return {'?',1,false};
        return {cp,n,true};
    }
    inline bool hasNonAscii(std::string_view s) {
        return std::any_of(s.begin(),s.end(),[](unsigned char c){return c>=128;});
    }
    struct Match { int image=-1; size_t end=0; };
    inline Match match(std::string_view s,size_t start,Catalog c) {
        Match best; uint32_t node=0; size_t at=start;
        if(c.nodes.empty()) return best;
        for(size_t step=0;step<MaxSequence&&at<s.size();++step) {
            auto r=decode(s,at); if(!r.valid) break;
            if(r.cp==0xfe0e) return {}; // Explicit text presentation.
            if(r.cp==0xfe0f&&at!=start) {
                at+=r.bytes; if(best.image>=0) best.end=at; continue;
            }
            auto const& n=c.nodes[node];
            auto begin=c.edges.begin()+n.first, end=begin+n.count;
            auto e=std::lower_bound(begin,end,r.cp,[](TrieEdge const& a,uint32_t b){return a.cp<b;});
            if(e==end||e->cp!=r.cp) break;
            node=e->next; at+=r.bytes;
            if(c.nodes[node].image>=0) best={c.nodes[node].image,at};
        }
        return best;
    }
    inline bool clusterPart(uint32_t cp) {
        return cp==0x200d||cp==0xfe0f||(cp>=0x1f3fb&&cp<=0x1f3ff)||(cp>=0xe0020&&cp<=0xe007f);
    }
    struct ImageSpan { size_t start,end; };
    struct Plan { std::string original,render; std::vector<uint16_t> images; std::vector<ImageSpan> spans; };
    inline Plan plan(std::string_view s,Catalog c) {
        Plan p; p.original=s; p.render.reserve(s.size());
        for(size_t at=0;at<s.size();) {
            auto m=match(s,at,c);
            if(m.image>=0) {
                auto next=decode(s,m.end);
                // Do not split an unknown joined or modified sequence into unrelated emoji.
                if(next.bytes&&clusterPart(next.cp)) {
                    size_t end=m.end; bool joined=false;
                    for(size_t i=0;i<MaxSequence&&end<s.size();++i) {
                        auto r=decode(s,end);
                        if(!clusterPart(r.cp)&&!joined) break;
                        joined=r.cp==0x200d; end+=r.bytes;
                    }
                    p.render+='?'; at=end; continue;
                }
                if(p.images.size()<MaxGlyphs&&s.size()<=MaxBytes) {
                    p.images.push_back(static_cast<uint16_t>(m.image)); p.spans.push_back({at,m.end}); p.render+=Placeholder;
                } else p.render+='?';
                at=m.end; continue;
            }
            auto r=decode(s,at);
            if(!r.valid||r.cp==static_cast<unsigned char>(Placeholder)||r.cp>0xffff) p.render+='?';
            else p.render.append(s.substr(at,r.bytes));
            at+=r.bytes;
        }
        return p;
    }
    // Byte-filter support is scoped to known emoji in a free-text input.
    inline std::string inputBytes(std::string_view s,Catalog c) {
        bool bytes[256]{};
        for(size_t at=0;at<s.size();) {
            auto m=match(s,at,c);
            if(m.image>=0) {
                for(size_t i=at;i<m.end;++i) { auto b=static_cast<unsigned char>(s[i]); if(b>=128) bytes[b]=true; }
                at=m.end;
            } else at+=decode(s,at).bytes;
        }
        std::string result; for(unsigned int i=128;i<256;++i) if(bytes[i]) result+=static_cast<char>(i);
        return result;
    }
    inline int sourceUnits(std::string_view text,bool utf16) {
        int count=0;
        for(size_t at=0;at<text.size();) {auto r=decode(text,at);count+=utf16&&r.cp>0xffff?2:1;at+=r.bytes;}
        return count;
    }
    inline int cursorIndex(std::string_view text,int sourceIndex,Catalog c,bool utf16=true) {
        if(sourceIndex<=0) return sourceIndex;
        int source=0,display=0;
        for(size_t at=0;at<text.size();) {
            auto m=match(text,at,c);
            size_t end=m.image>=0?m.end:at+decode(text,at).bytes;
            auto units=sourceUnits(text.substr(at,end-at),utf16);
            if(source+units>sourceIndex) return display;
            source+=units; ++display; at=end;
            if(source==sourceIndex) return display;
        }
        return display;
    }
    inline int sourceCursor(std::string_view text,int displayIndex,Catalog c,bool utf16) {
        if(displayIndex<0) return -1;
        int source=0,display=0;
        for(size_t at=0;at<text.size()&&display<displayIndex;++display) {
            auto m=match(text,at,c);size_t end=m.image>=0?m.end:at+decode(text,at).bytes;
            source+=sourceUnits(text.substr(at,end-at),utf16);at=end;
        }
        return source;
    }
    inline size_t cursorByte(std::string_view text,int sourceIndex,Catalog c,bool utf16) {
        if(sourceIndex<0) return text.size();
        int source=0;
        for(size_t at=0;at<text.size();) {
            if(source>=sourceIndex) return at;
            auto m=match(text,at,c);size_t end=m.image>=0?m.end:at+decode(text,at).bytes;
            auto units=sourceUnits(text.substr(at,end-at),utf16);
            if(source+units>sourceIndex) return at;
            source+=units;at=end;
        }
        return text.size();
    }
    // A whole cluster either fits or is rejected; never cut UTF-8 at a byte limit.
    inline std::string insertAtCursor(std::string_view original,std::string_view addition,int cursor,
                                     Catalog c,bool utf16,size_t byteLimit) {
        if(original.size()>byteLimit||addition.size()>byteLimit-original.size()) return std::string(original);
        auto at=cursorByte(original,cursor,c,utf16);
        return std::string(original.substr(0,at))+std::string(addition)+std::string(original.substr(at));
    }
    inline std::string shorten(std::string_view text,size_t limit,Catalog c) {
        if(text.size()<=limit) return std::string(text);
        if(limit<3) return std::string(limit,'.');
        size_t at=0,end=0;
        while(at<text.size()) {
            auto m=match(text,at,c); size_t next=m.image>=0?m.end:at+decode(text,at).bytes;
            if(next>limit-3) break;
            end=at=next;
        }
        return std::string(text.substr(0,end))+"...";
    }
}
