#pragma once
#include <Geode/Geode.hpp>
#include <algorithm>
#include <cmath>
using namespace geode::prelude;
namespace mucho::media {
class AttachmentViewport final:public CCLayer {
    CCClippingNode* m_clip=nullptr;
    CCSprite* m_image=nullptr;
    float m_fit=1.f,m_zoom=1.f,m_max=2.f;
    int m_touch=-1;
    CCPoint m_start,m_position;
    void clamp(){
        if(!m_image)return;auto size=getContentSize();auto image=m_image->getContentSize()*m_zoom;auto p=m_image->getPosition();
        p.x=image.width<=size.width?size.width/2:std::clamp(p.x,size.width-image.width/2,image.width/2);
        p.y=image.height<=size.height?size.height/2:std::clamp(p.y,size.height-image.height/2,image.height/2);
        m_image->setPosition(p);
    }
public:
    void image(CCSprite* sprite){
        m_image=sprite;m_clip->addChild(sprite);sprite->setPosition(getContentSize()/2);
    }
    void loaded(){
        auto size=m_image->getTexture()->getContentSize();auto pixels=m_image->getTexture()->getContentSizeInPixels();
        m_fit=std::min(getContentSize().width/size.width,getContentSize().height/size.height);
        m_max=std::max(m_fit,std::max(2.f,pixels.width/size.width));fit();
    }
    void fit(){if(!m_image)return;m_zoom=m_fit;m_image->setScale(m_zoom);m_image->setPosition(getContentSize()/2);clamp();}
    void zoom(float factor){
        if(!m_image||!m_image->isVisible())return;auto next=std::clamp(m_zoom*factor,m_fit,m_max);auto center=getContentSize()/2;
        m_image->setPosition(center+(m_image->getPosition()-center)*(next/m_zoom));m_zoom=next;m_image->setScale(m_zoom);clamp();
    }
    void registerWithTouchDispatcher()override{CCTouchDispatcher::get()->addTargetedDelegate(this,kCCMenuHandlerPriority,true);}
    bool ccTouchBegan(CCTouch* touch,CCEvent*)override{
        if(!m_image||!m_image->isVisible()||!isVisible()||m_touch>=0)return false;auto p=convertToNodeSpace(touch->getLocation());
        if(!CCRect{{0,0},getContentSize()}.containsPoint(p))return false;m_touch=touch->getID();m_start=p;m_position=m_image->getPosition();return true;
    }
    void ccTouchMoved(CCTouch* touch,CCEvent*)override{if(touch->getID()!=m_touch||!m_image)return;m_image->setPosition(m_position+convertToNodeSpace(touch->getLocation())-m_start);clamp();}
    void ccTouchEnded(CCTouch* touch,CCEvent*)override{if(touch->getID()==m_touch)m_touch=-1;}
    void ccTouchCancelled(CCTouch* touch,CCEvent* event)override{ccTouchEnded(touch,event);}
    static AttachmentViewport* create(CCSize size){
        auto layer=new AttachmentViewport;if(!layer->init()){delete layer;return nullptr;}
        layer->ignoreAnchorPointForPosition(false);layer->setAnchorPoint({0,0});layer->setContentSize(size);
        auto stencil=CCDrawNode::create();CCPoint points[]={{0,0},{size.width,0},{size.width,size.height},{0,size.height}};
        stencil->drawPolygon(points,4,{1,1,1,1},0,{0,0,0,0});layer->m_clip=CCClippingNode::create();layer->m_clip->setStencil(stencil);layer->addChild(layer->m_clip);layer->setTouchEnabled(true);
        layer->autorelease();return layer;
    }
};
}
