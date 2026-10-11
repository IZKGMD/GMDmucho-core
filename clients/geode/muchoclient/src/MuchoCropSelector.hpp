#pragma once
#include <Geode/Geode.hpp>
#include "MuchoCropRules.hpp"
#include <functional>

using namespace geode::prelude;

namespace mucho::profile {
    class CropSelector final : public CCLayer {
        CCSprite* m_image = nullptr;
        CCDrawNode* m_overlay = nullptr;
        CCLabelBMFont* m_hint = nullptr;
        double m_width = 0, m_height = 0, m_outW = 960, m_outH = 320;
        float m_fit = 1;
        CCRect m_imageBox, m_frame, m_startFrame;
        int m_x = 500, m_y = 500, m_zoom = 100, m_rotation = 0;
        int m_touch = -1, m_drag = 0, m_sx = 1, m_sy = 1;
        bool m_enabled = false;
        CCPoint m_start, m_fixed;
        std::function<void(int,int,int)> m_changed;

        CropRegion region(int zoom) const { return cropRegion(m_width,m_height,m_x,m_y,zoom,m_rotation,m_outW,m_outH); }
        void rect(CCRect r,ccColor4F color) {
            if (r.size.width <= 0 || r.size.height <= 0) return;
            CCPoint p[] = {{r.getMinX(),r.getMinY()},{r.getMaxX(),r.getMinY()},
                {r.getMaxX(),r.getMaxY()},{r.getMinX(),r.getMaxY()}};
            m_overlay->drawPolygon(p,4,color,0,{0,0,0,0});
        }
        void redraw() {
            m_overlay->clear();
            if (m_width <= 0 || m_height <= 0) return;
            auto r = region(m_zoom); auto size = getContentSize();
            m_fit = static_cast<float>(std::min((size.width-12)/r.imageWidth,(size.height-12)/r.imageHeight));
            m_imageBox = {(size.width-static_cast<float>(r.imageWidth)*m_fit)/2,
                (size.height-static_cast<float>(r.imageHeight)*m_fit)/2,
                static_cast<float>(r.imageWidth)*m_fit,static_cast<float>(r.imageHeight)*m_fit};
            m_image->setRotation(static_cast<float>(m_rotation));
            auto textureSize = m_image->getTexture()->getContentSize();
            m_image->setScale(static_cast<float>(m_width)*m_fit/textureSize.width);
            m_image->setPosition(size/2);
            m_frame = {m_imageBox.getMinX()+static_cast<float>(r.left)*m_fit,
                m_imageBox.getMinY()+static_cast<float>(r.imageHeight-r.top-r.height)*m_fit,
                static_cast<float>(r.width)*m_fit,static_cast<float>(r.height)*m_fit};
            ccColor4F shade{0,0,0,.62f};
            rect({m_imageBox.getMinX(),m_imageBox.getMinY(),m_imageBox.size.width,m_frame.getMinY()-m_imageBox.getMinY()},shade);
            rect({m_imageBox.getMinX(),m_frame.getMaxY(),m_imageBox.size.width,m_imageBox.getMaxY()-m_frame.getMaxY()},shade);
            rect({m_imageBox.getMinX(),m_frame.getMinY(),m_frame.getMinX()-m_imageBox.getMinX(),m_frame.size.height},shade);
            rect({m_frame.getMaxX(),m_frame.getMinY(),m_imageBox.getMaxX()-m_frame.getMaxX(),m_frame.size.height},shade);
            for (int i=1;i<=2;++i) {
                float x = m_frame.getMinX()+m_frame.size.width*i/3, y = m_frame.getMinY()+m_frame.size.height*i/3;
                m_overlay->drawSegment({x,m_frame.getMinY()},{x,m_frame.getMaxY()},.35f,{1,1,1,.4f});
                m_overlay->drawSegment({m_frame.getMinX(),y},{m_frame.getMaxX(),y},.35f,{1,1,1,.4f});
            }
            CCPoint p[] = {{m_frame.getMinX(),m_frame.getMinY()},{m_frame.getMaxX(),m_frame.getMinY()},
                {m_frame.getMaxX(),m_frame.getMaxY()},{m_frame.getMinX(),m_frame.getMaxY()}};
            m_overlay->drawPolygon(p,4,{0,0,0,0},1.1f,{1,1,1,1});
            for (auto point:p) { m_overlay->drawDot(point,3.5f,{1,1,1,1}); m_overlay->drawDot(point,1.8f,{.25f,.85f,1,1}); }
        }
        void emit(double left,double top,int zoom) {
            auto r = region(zoom);
            int x = cropPosition(left,r.imageWidth-r.width), y = cropPosition(top,r.imageHeight-r.height);
            if (m_changed) m_changed(x,y,zoom);
        }
    public:
        CCSize imageSize() const { return {static_cast<float>(m_width),static_cast<float>(m_height)}; }
        bool dragging() const { return m_touch >= 0; }
        void active(bool enabled) { m_enabled=enabled; if (!enabled) { m_touch=-1; m_drag=0; } }
        void clear() {
            active(false); m_width=m_height=0; m_overlay->clear(); m_hint->setVisible(true);
            m_image->removeFromParent(); m_image=CCSprite::create(); m_image->setVisible(false); addChild(m_image,1);
        }
        void texture(CCTexture2D* texture) {
            if (!texture) return;
            auto pixels=texture->getContentSizeInPixels(); m_width=pixels.width; m_height=pixels.height;
            m_image->setTexture(texture); m_image->setTextureRect({{0,0},texture->getContentSize()});
            m_image->setVisible(true); m_hint->setVisible(false); redraw();
        }
        void crop(int x,int y,int zoom,int rotation) { m_x=x; m_y=y; m_zoom=zoom; m_rotation=rotation; redraw(); }
        void registerWithTouchDispatcher() override { CCTouchDispatcher::get()->addTargetedDelegate(this,kCCMenuHandlerPriority,true); }
        bool ccTouchBegan(CCTouch* touch,CCEvent*) override {
            if (!m_enabled || !isVisible() || m_width <= 0 || m_touch >= 0) return false;
            auto point=convertToNodeSpace(touch->getLocation());
            if (!CCRect{{0,0},getContentSize()}.containsPoint(point)) return false;
            m_start=point; m_startFrame=m_frame; m_drag=1;
            double nearest=10;
            for (int sx:{-1,1}) for (int sy:{-1,1}) {
                CCPoint corner{sx>0?m_frame.getMaxX():m_frame.getMinX(),sy>0?m_frame.getMaxY():m_frame.getMinY()};
                double distance=std::hypot(point.x-corner.x,point.y-corner.y);
                if (distance<=nearest) {
                    nearest=distance;
                    m_sx=sx; m_sy=sy; m_drag=2;
                    m_fixed=CCPoint{sx>0?m_frame.getMinX():m_frame.getMaxX(),sy>0?m_frame.getMinY():m_frame.getMaxY()};
                }
            }
            if (m_drag==1 && !m_imageBox.containsPoint(point)) return false;
            m_touch=touch->getID();
            if (m_drag==1 && !m_frame.containsPoint(point)) {
                auto r=region(m_zoom);
                emit((point.x-m_imageBox.getMinX())/m_fit-r.width/2,
                    r.imageHeight-(point.y-m_imageBox.getMinY())/m_fit-r.height/2,m_zoom);
                m_startFrame=m_frame;
            }
            return true;
        }
        void ccTouchMoved(CCTouch* touch,CCEvent*) override {
            if (!m_enabled || touch->getID()!=m_touch) return;
            auto point=convertToNodeSpace(touch->getLocation()); auto r=region(m_zoom);
            if (m_drag==1) {
                auto delta=point-m_start;
                emit((m_startFrame.getMinX()+delta.x-m_imageBox.getMinX())/m_fit,
                    r.imageHeight-r.height-(m_startFrame.getMinY()+delta.y-m_imageBox.getMinY())/m_fit,m_zoom);
            } else {
                double ratio=m_outW/m_outH;
                double dx=(point.x-m_fixed.x)*m_sx,dy=(point.y-m_fixed.y)*m_sy;
                double requested=(dx+dy/ratio)/(1+1/(ratio*ratio));
                double maxW=std::min<double>(m_sx>0?m_imageBox.getMaxX()-m_fixed.x:m_fixed.x-m_imageBox.getMinX(),
                    (m_sy>0?m_imageBox.getMaxY()-m_fixed.y:m_fixed.y-m_imageBox.getMinY())*ratio);
                auto base=region(100);
                double baseW=base.width*m_fit;
                int zoom=cropZoom(baseW,std::clamp(requested,baseW/3,std::max(baseW/3,maxW)));
                r=region(zoom);
                double left=(m_fixed.x-m_imageBox.getMinX())/m_fit-(m_sx<0?r.width:0);
                double bottom=(m_fixed.y-m_imageBox.getMinY())/m_fit-(m_sy<0?r.height:0);
                emit(left,r.imageHeight-r.height-bottom,zoom);
            }
        }
        void ccTouchEnded(CCTouch* touch,CCEvent*) override { if (touch->getID()==m_touch) { m_touch=-1; m_drag=0; } }
        void ccTouchCancelled(CCTouch* touch,CCEvent* event) override { ccTouchEnded(touch,event); }
        static CropSelector* create(CCSize size,bool avatar,std::function<void(int,int,int)> changed) {
            auto layer=new CropSelector();
            if (!layer->init()) { delete layer; return nullptr; }
            layer->ignoreAnchorPointForPosition(false); layer->setAnchorPoint({0,0}); layer->setContentSize(size);
            layer->m_changed=std::move(changed); if (avatar) layer->m_outW=layer->m_outH=256;
            auto background=CCLayerColor::create({20,28,45,255},size.width,size.height); layer->addChild(background);
            layer->m_image=CCSprite::create(); layer->m_image->setVisible(false); layer->addChild(layer->m_image,1);
            layer->m_overlay=CCDrawNode::create(); layer->addChild(layer->m_overlay,2);
            layer->m_hint=CCLabelBMFont::create("Choose a photo to select an area","bigFont.fnt");
            layer->m_hint->limitLabelWidth(size.width-20,.3f,0.f); layer->m_hint->setPosition(size/2); layer->addChild(layer->m_hint,3);
            layer->setTouchEnabled(true); layer->autorelease(); return layer;
        }
    };
}
