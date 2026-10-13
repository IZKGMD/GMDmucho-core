#include "MuchoEmoji.hpp"
#include "MuchoEmojiData.hpp"
#include "MuchoUi.hpp"
#include <Geode/Geode.hpp>
#include <Geode/modify/CCLabelBMFont.hpp>
#include <Geode/modify/CCSpriteBatchNode.hpp>
#include <Geode/modify/CCBMFontConfiguration.hpp>
#include <Geode/modify/MultilineBitmapFont.hpp>
#include <Geode/modify/CCTextInputNode.hpp>
#include <Geode/loader/GameEvent.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/utils/general.hpp>
#include <Geode/utils/string.hpp>
#include <array>
#include <cmath>
#include <cstdlib>
#include <cstring>

using namespace geode::prelude;
namespace mucho::emoji {
namespace {
    constexpr char const* StateKey="mucho-emoji-label";
    bool enabled=true, stopped=false;
    std::vector<WeakRef<CCLabelBMFont>> labels;
    struct Slot { size_t index; uint16_t id; Ref<CCSprite> image; };
    class State final : public CCObject {
    public:
        Plan text; Ref<CCNode> layer; std::vector<Slot> slots; bool dirty=true;
        std::array<Ref<CCSpriteBatchNode>,data::PageCount> batches;
    };
    State* state(CCNode* node) { return static_cast<State*>(node->getUserObject(StateKey)); }
    std::string fontText(std::string_view text,CCBMFontConfiguration* config) {
        std::string result; result.reserve(text.size());
        for(size_t at=0;at<text.size();) {
            auto r=decode(text,at);
            if(r.cp>=128&&config&&config->m_pCharacterSet&&config->m_pCharacterSet->find(r.cp)==config->m_pCharacterSet->end())
                result+='?';
            else result.append(text.substr(at,r.bytes));
            at+=r.bytes;
        }
        return result;
    }
    void remember(CCLabelBMFont* label) {
        std::erase_if(labels,[](auto const& weak){return !weak.lock();});
        for(auto const& weak:labels) if(weak.lock().data()==label) return;
        if(labels.size()<512) labels.emplace_back(label);
    }
    bool installMarker(CCBMFontConfiguration* config) {
        if(!config||!config->m_pCharacterSet||config->m_nCommonHeight<1||config->m_nCommonHeight>1024) return false;
        unsigned int key=static_cast<unsigned char>(Placeholder);
        tCCFontDefHashElement* found=nullptr;
        HASH_FIND_INT(config->m_pFontDefDictionary,&key,found);
        if(found) return true;
        auto entry=static_cast<tCCFontDefHashElement*>(std::calloc(1,sizeof(tCCFontDefHashElement)));
        if(!entry) return false;
        auto h=config->m_nCommonHeight;
        entry->key=key; entry->fontDef.charID=key;
        entry->fontDef.rect=CCRect(0,0,static_cast<float>(h),static_cast<float>(h));
        entry->fontDef.xAdvance=static_cast<short>(h);
        // Cocos owns this allocation and frees it with the font configuration.
        HASH_ADD_INT(config->m_pFontDefDictionary,key,entry);
        config->m_pCharacterSet->insert(key); return true;
    }
    CCSprite* image(uint16_t id) {
        if(stopped||id>=data::ImageCount) return nullptr;
        auto page=id/data::PerPage, cell=id%data::PerPage;
        auto path=Mod::get()->getResourcesDir()/fmt::format("emoji-page-{}.png",page);
        auto texture=CCTextureCache::get()->addImage(utils::string::pathToString(path).c_str(),false);
        if(!texture) return nullptr;
        auto rect=CC_RECT_PIXELS_TO_POINTS(CCRect(
            static_cast<float>((cell%data::Columns)*data::Cell+2),
            static_cast<float>((cell/data::Columns)*data::Cell+2),
            static_cast<float>(data::Tile),static_cast<float>(data::Tile)));
        return CCSprite::createWithTexture(texture,rect);
    }
    void rebuild(CCLabelBMFont* label,State* value) {
        value->dirty=false;
        if(!value->layer) {value->layer=CCNode::create(); value->layer->setAnchorPoint({0,0});}
        if(!label->m_sString) return;
        size_t ordinal=0;
        for(size_t i=0;label->m_sString[i]&&i<MaxBytes;++i) {
            if(label->m_sString[i]!=static_cast<unsigned char>(Placeholder)) continue;
            if(ordinal>=value->text.images.size()) break;
            auto id=value->text.images[ordinal];
            if(ordinal<value->slots.size()&&value->slots[ordinal].id==id) {
                value->slots[ordinal].index=i; ++ordinal; continue;
            }
            if(ordinal<value->slots.size()&&value->slots[ordinal].image)
                value->slots[ordinal].image->removeFromParentAndCleanup(true);
            auto sprite=image(id);
            if(sprite) {
                auto page=id/data::PerPage;
                auto& batch=value->batches[page];
                if(!batch) {
                    batch=CCSpriteBatchNode::createWithTexture(sprite->getTexture(),8);
                    batch->setAnchorPoint({0,0}); value->layer->addChild(batch);
                } else if(batch->getTexture()!=sprite->getTexture()) batch->setTexture(sprite->getTexture());
                batch->addChild(sprite);
            }
            if(ordinal<value->slots.size()) value->slots[ordinal]={i,id,sprite};
            else value->slots.push_back({i,id,sprite});
            ++ordinal;
        }
        for(size_t i=ordinal;i<value->slots.size();++i)
            if(value->slots[i].image)value->slots[i].image->removeFromParentAndCleanup(true);
        value->slots.resize(ordinal);
    }
    void prepareDraw(CCLabelBMFont* label,State* value) {
        if(value->dirty) rebuild(label,value);
        float unit=label->m_pConfiguration?static_cast<float>(label->m_pConfiguration->m_nCommonHeight)/data::Tile:1.f;
        for(auto& slot:value->slots) {
            auto glyph=typeinfo_cast<CCSprite*>(label->getChildByTag(static_cast<int>(slot.index)));
            if(!glyph){if(slot.image)slot.image->setVisible(false);continue;}
            if(!slot.image) {
                unsigned int key='?'; tCCFontDefHashElement* fallback=nullptr;
                if(label->m_pConfiguration) HASH_FIND_INT(label->m_pConfiguration->m_pFontDefDictionary,&key,fallback);
                glyph->setTextureRect(fallback?CC_RECT_PIXELS_TO_POINTS(fallback->fontDef.rect):CCRect(0,0,0,0),false,glyph->getContentSize());
                continue;
            }
            slot.image->setVisible(glyph->isVisible());
            slot.image->setPosition(glyph->getPosition());
            slot.image->setScaleX(unit*glyph->getScaleX()); slot.image->setScaleY(unit*glyph->getScaleY());
            slot.image->setRotationX(glyph->getRotationX()); slot.image->setRotationY(glyph->getRotationY());
            slot.image->setSkewX(glyph->getSkewX()); slot.image->setSkewY(glyph->getSkewY());
            slot.image->setOpacity(glyph->getDisplayedOpacity());
            // Keep the marker's size, visibility and opacity for native wrapping/fades;
            // a zero texture rectangle prevents its font-atlas quad from drawing.
            glyph->setTextureRect(CCRect(0,0,0,0),false,glyph->getContentSize());
        }
    }
    bool freeText(CCTextInputNode* input) {
        if(input->m_usePasswordChar||input->m_numberInput) return false;
        std::string_view filter(input->m_allowedChars.c_str(),input->m_allowedChars.size());
        return filter.empty()||(filter.find(' ')!=std::string_view::npos&&filter.find('a')!=std::string_view::npos);
    }
    struct Filter {
        CCTextInputNode* input; gd::string original; std::string applied; bool changed=false;
        Filter(CCTextInputNode* node,std::string_view text):input(node),original(node->m_allowedChars) {
            if(enabled&&!stopped&&freeText(node)&&!original.empty()) {
                auto extra=inputBytes(text,data::catalog());
                std::string extended(original.c_str(),original.size());
                for(char c:extra) if(extended.find(c)==std::string::npos) extended+=c;
                if(extended!=std::string_view(original.c_str(),original.size())) {
                    applied=std::move(extended); input->m_allowedChars=applied; changed=true;
                }
            }
        }
        ~Filter(){
            // Preserve an intentional filter change made by an input callback.
            if(changed&&std::string_view(input->m_allowedChars.c_str(),input->m_allowedChars.size())==applied)
                input->m_allowedChars=std::move(original);
        }
    };
    void refresh(bool value) {
        enabled=value;
        auto current=labels;
        for(auto const& weak:current) if(auto label=weak.lock()) if(auto st=state(label)) {
            auto original=st->text.original; label->setString(original.c_str());
        }
    }
}
std::string shortenText(std::string_view text,size_t limit) { return shorten(text,limit,data::catalog()); }
$on_mod(Loaded) {
    enabled=Mod::get()->getSettingValue<bool>("emoji-support");
    listenForSettingChanges<bool>("emoji-support",[](bool value){refresh(value);});
}
$on_game(Exiting) {
    stopped=true;
    for(auto const& weak:labels) if(auto label=weak.lock()) if(auto st=state(label)) {
        st->slots.clear(); st->layer=nullptr;
        for(auto& batch:st->batches)batch=nullptr;
    }
    labels.clear();
}
}

class $modify(MuchoEmojiFontConfig,CCBMFontConfiguration) {
    bool initWithFNTfile(char const* file) {
        auto ok=CCBMFontConfiguration::initWithFNTfile(file);
        if(ok) mucho::emoji::installMarker(this);
        return ok;
    }
};
class $modify(MuchoEmojiLabel,CCLabelBMFont) {
    using CCLabelBMFont::setString;
    void setString(char const* text) { this->setString(text,true); }
    void setString(char const* text,bool update) {
        using namespace mucho::emoji;
        if(!update){CCLabelBMFont::setString(text,update);return;}
        auto old=state(this);
        if(!old&&(!enabled||stopped||m_bIsBatched||!hasNonAscii(text?text:""))) {
            CCLabelBMFont::setString(text,true); return;
        }
        // Copy before dropping old state: callers may pass our getString() result.
        std::string original=text?text:"";
        if(!enabled||stopped||m_bIsBatched||!hasNonAscii(original)||!installMarker(m_pConfiguration)) {
            if(old) setUserObject(StateKey,nullptr);
            CCLabelBMFont::setString(original.c_str(),true); return;
        }
        auto parsed=plan(original,data::catalog());
        // Missing BMP letters get a stable fallback slot instead of leaving
        // holes in the native bitmap-font wrapping/cursor arrays.
        parsed.render=fontText(parsed.render,m_pConfiguration);
        if(parsed.images.empty()) {
            if(old) setUserObject(StateKey,nullptr);
            CCLabelBMFont::setString(parsed.render.c_str(),true);
            // Unicode repairs are presentation-only.
            m_sInitialStringUTF8=original.c_str(); return;
        }
        auto value=old;
        if(!value) { value=new State(); setUserObject(StateKey,value); value->release(); remember(this); }
        value->text=std::move(parsed); value->dirty=true;
        CCLabelBMFont::setString(value->text.render.c_str(),true);
        m_sInitialStringUTF8=value->text.original.c_str();
    }
    void createFontChars() {
        CCLabelBMFont::createFontChars();
        if(auto value=mucho::emoji::state(this)) value->dirty=true;
    }
};
class $modify(MuchoEmojiDraw,CCSpriteBatchNode) {
    void visit() {
        using namespace mucho::emoji;
        if(!isVisible()){CCSpriteBatchNode::visit();return;}
        auto value=state(this);
        auto label=value?typeinfo_cast<CCLabelBMFont*>(this):nullptr;
        if(label&&!stopped) prepareDraw(label,value);
        CCSpriteBatchNode::visit();
        if(label&&!stopped&&value->layer) {
            // This separate node uses the same parent transform and active clipping
            // as the font. Different textures never enter a font sprite batch.
            value->layer->setAdditionalTransform(label->nodeToParentTransform());
            value->layer->visit();
        }
    }
};
class $modify(MuchoEmojiMultiline,MultilineBitmapFont) {
    bool initWithFont(char const* font,gd::string text,float scale,float width,CCPoint anchor,int height,bool disableColor) {
        using namespace mucho::emoji;
        std::string_view view(text.c_str(),text.size());
        if(!enabled||stopped||!hasNonAscii(view))
            return MultilineBitmapFont::initWithFont(font,text,scale,width,anchor,height,disableColor);
        auto parsed=plan(view,data::catalog());
        // Retain GD's rich-text path when descriptors are present. Plain emoji
        // paragraphs use one normal bitmap label, including native line wrapping.
        bool descriptors=view.find("<c")!=std::string_view::npos||view.find("</c>")!=std::string_view::npos||
            view.find("<d")!=std::string_view::npos||view.find("<i")!=std::string_view::npos||view.find("<s")!=std::string_view::npos;
        if(parsed.images.empty()||descriptors||scale<=0||width<=0)
            return MultilineBitmapFont::initWithFont(font,text,scale,width,anchor,height,disableColor);
        if(!MultilineBitmapFont::initWithFont(font,"",scale,width,anchor,height,disableColor)) return false;
        removeAllChildrenWithCleanup(true);
        if(m_lines)m_lines->removeAllObjects();
        if(m_characters)m_characters->removeAllObjects();
        auto label=CCLabelBMFont::create(text.c_str(),font,width/scale,kCCTextAlignmentLeft);
        if(!label) return false;
        label->setAnchorPoint({0,0}); label->setScale(scale); label->setPosition({0,0});
        addChild(label);
        if(m_lines) m_lines->addObject(label);
        if(m_characters) for(auto glyph:CCArrayExt<CCNode*>(label->getChildren())) m_characters->addObject(glyph);
        auto size=label->getContentSize()*scale;
        setContentSize(size); setAnchorPoint(anchor);
        m_width=static_cast<int>(std::ceil(size.width)); m_height=static_cast<int>(std::ceil(size.height));
        return true;
    }
};
namespace mucho::emoji { void showPickerFor(CCTextInputNode* input); }
class $modify(MuchoEmojiInput,CCTextInputNode) {
    struct Fields { Ref<CCMenu> menu; };
    void onEmoji(CCObject*) {
        if(mucho::emoji::freeText(this)) mucho::emoji::showPickerFor(this);
    }
    void visit() {
        using namespace mucho::emoji;
        bool eligible=enabled&&!stopped&&freeText(this)&&isVisible()&&getContentWidth()>=60;
        if(eligible&&!m_fields->menu) {
            auto face=CCLabelBMFont::create("\U0001f60a","bigFont.fnt");
            face->setScale(20.f/std::max(1.f,face->getContentHeight()));
            auto button=mucho::button(face,this,menu_selector(MuchoEmojiInput::onEmoji));
            button->setContentSize({26,26}); face->setPosition({13,13});
            button->setID("mucho-emoji-button");
            auto menu=CCMenu::create(); menu->setID("mucho-emoji-input-menu");
            menu->setPosition({0,0}); menu->addChild(button); addChild(menu,100);
            m_fields->menu=menu;
        }
        if(m_fields->menu) {
            m_fields->menu->setVisible(eligible);
            // Inputs use a centred coordinate system. Keep the control inside
            // the right edge so popup clipping does not hide it.
            auto button=m_fields->menu->getChildren()->objectAtIndex(0);
            static_cast<CCNode*>(button)->setPosition({getContentWidth()/2.f-14.f,0});
        }
        CCTextInputNode::visit();
    }
    void updateBlinkLabelToChar(int index) {
        if(m_textLabel&&mucho::emoji::state(m_textLabel)) {
            auto source=getString();
            index=mucho::emoji::cursorIndex(std::string_view(source.c_str(),source.size()),index,mucho::emoji::data::catalog());
        }
        CCTextInputNode::updateBlinkLabelToChar(index);
    }
    void updateLabel(gd::string text) {
        if(!mucho::emoji::enabled||mucho::emoji::stopped||!mucho::emoji::hasNonAscii(std::string_view(text.c_str(),text.size()))) {
            CCTextInputNode::updateLabel(text); return;
        }
        mucho::emoji::Filter guard(this,std::string_view(text.c_str(),text.size()));
        CCTextInputNode::updateLabel(text);
    }
    bool onTextFieldInsertText(CCTextFieldTTF* sender,char const* text,int length,enumKeyCodes keys) {
        if(!text||length<=0||!mucho::emoji::enabled||mucho::emoji::stopped||
            !mucho::emoji::hasNonAscii(std::string_view(text,static_cast<size_t>(length))))
            return CCTextInputNode::onTextFieldInsertText(sender,text,length,keys);
        mucho::emoji::Filter guard(this,std::string_view(text,static_cast<size_t>(length)));
        return CCTextInputNode::onTextFieldInsertText(sender,text,length,keys);
    }
};

namespace mucho::emoji {
namespace {
    constexpr std::array<char const*,48> Choices={
        "\U0001f600","\U0001f603","\U0001f604","\U0001f601","\U0001f606","\U0001f605","\U0001f602","\U0001f923",
        "\U0001f642","\U0001f609","\U0001f60a","\U0001f60d","\U0001f970","\U0001f618","\U0001f60e","\U0001f913",
        "\U0001f914","\U0001f928","\U0001f62d","\U0001f622","\U0001f621","\U0001f633","\U0001f631","\U0001f634",
        "\U0001f525","\U0001f4af","\U0001f480","\U0001f4a9","\U0001f47b","\U0001f916","\U0001f389","\U0001f38a",
        "\u2764\ufe0f","\U0001f494","\U0001f49c","\U0001f49a","\U0001f499","\U0001f49b","\u2b50","\u2728",
        "\U0001f44d","\U0001f44e","\U0001f44f","\U0001f64f","\U0001f4aa","\U0001f440","\U0001f680","\U0001f3c6"
    };
    class Picker final : public Popup {
        CCLabelBMFont* m_status=nullptr;
        WeakRef<CCTextInputNode> m_target;
        bool init(CCTextInputNode* target) {
            if(!Popup::init(360,286)) return false;
            m_target=target;
            setTitle("Mucho Emoji");
            auto tip=CCLabelBMFont::create(target?"Tap an emoji to insert it into your text.":"Tap to copy. Paste into a comment or message.","bigFont.fnt");
            tip->setScale(.28f); tip->setPosition({180,241}); m_mainLayer->addChild(tip);
            auto menu=CCMenu::create(); menu->setPosition({180,207}); m_mainLayer->addChild(menu);
            for(size_t i=0;i<Choices.size();++i) {
                auto text=CCLabelBMFont::create(Choices[i],"bigFont.fnt");
                text->setScale(24.f/std::max(1.f,text->getContentHeight()));
                auto item=mucho::button(text,this,menu_selector(Picker::onChoice)); item->setTag(static_cast<int>(i));
                item->setContentSize({32,32}); text->setPosition({16,16});
                item->setPosition({(static_cast<int>(i%8)-3.5f)*39.f,-static_cast<float>(i/8)*30.f});
                menu->addChild(item);
            }
            m_status=CCLabelBMFont::create("4009 emoji | offline | Twemoji 17","bigFont.fnt");
            m_status->setScale(.28f); m_status->setPosition({180,28}); m_mainLayer->addChild(m_status);
            motion::panel(m_mainLayer); return true;
        }
        void onChoice(CCObject* sender) {
            auto item=typeinfo_cast<CCNode*>(sender); if(!item) return;
            auto index=item->getTag(); if(index<0||static_cast<size_t>(index)>=Choices.size()) return;
            if(auto input=m_target.lock()) {
                if(!freeText(input)||!input->m_textField) return;
                auto text=Choices[static_cast<size_t>(index)];
                // Use the native insertion path: preserve cursor, length checks,
                // swear filtering, and TextInputDelegate notifications.
                Filter guard(input,text);
                input->m_textField->insertText(text,static_cast<int>(std::strlen(text)),KEY_None);
                onClose(nullptr);
                return;
            }
            if(clipboard::write(Choices[static_cast<size_t>(index)]))
                m_status->setString((std::string("Copied ")+Choices[static_cast<size_t>(index)]+" - paste into your text").c_str());
            else m_status->setString("Clipboard unavailable on this device");
            m_status->limitLabelWidth(330,.28f,.18f);
        }
    public:
        static Picker* create(CCTextInputNode* target=nullptr) { auto p=new Picker(); if(p->init(target)){p->autorelease();return p;} delete p; return nullptr; }
    };
}
void showPickerFor(CCTextInputNode* input) {
    if(enabled&&!stopped&&input&&freeText(input)) if(auto popup=Picker::create(input)) popup->show();
}
void showPicker() {
    if(!enabled) { FLAlertLayer::create("Emoji", "Enable Emoji Support in MuchoClient settings first.","OK")->show(); return; }
    if(auto popup=Picker::create()) popup->show();
}
}
