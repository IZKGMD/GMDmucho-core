#pragma once
#include <Geode/Geode.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/ui/TextInput.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoClanRules.hpp"
#include "MuchoUi.hpp"
#include "MuchoEmoji.hpp"
#include "MuchoLayoutRules.hpp"
class MuchoCreatorHub final:public geode::Popup {
 std::string m_origin,m_view="mine",m_notice,m_back="mine",m_draft;int m_account=0,m_page=1,m_slice=0,m_history=0,m_selectedLevel=0;
 bool m_busy=false,m_closed=false,m_queued=false,m_review=false,m_more=false;matjson::Value m_data;
 cocos2d::CCNode* m_body=nullptr;geode::TextInput* m_input=nullptr;geode::async::TaskHolder<geode::utils::web::WebResponse> m_task;
 void render();void redraw();void request(std::string action,std::vector<std::pair<std::string,std::string>> fields={});
 void list(std::string view);void onMine(cocos2d::CCObject*);void onLevels(cocos2d::CCObject*);void onQueue(cocos2d::CCObject*);void onArchive(cocos2d::CCObject*);
 void onRow(cocos2d::CCObject*);void onPrev(cocos2d::CCObject*);void onNext(cocos2d::CCObject*);void onRefresh(cocos2d::CCObject*);void onBack(cocos2d::CCObject*);
 void onSubmit(cocos2d::CCObject*);void onClaim(cocos2d::CCObject*);void onDecision(cocos2d::CCObject*);void onWithdraw(cocos2d::CCObject*);void onRelease(cocos2d::CCObject*);void onLevel(cocos2d::CCObject*);
 void onReadFeedback(cocos2d::CCObject*);void onReadAuthor(cocos2d::CCObject*);
 void onHistoryPrev(cocos2d::CCObject*);void onHistoryNext(cocos2d::CCObject*);
 void write(std::string action,std::vector<std::pair<std::string,std::string>> fields={});
 void label(std::string value,float x,float y,float scale=.32f,float width=400);void button(char const* value,float x,float y,cocos2d::SEL_MenuHandler handler,int tag=0,float scale=.35f);
 bool init(std::string origin);void onClose(cocos2d::CCObject*) override;void onExit() override;
public:static MuchoCreatorHub* create(std::string origin);
};
