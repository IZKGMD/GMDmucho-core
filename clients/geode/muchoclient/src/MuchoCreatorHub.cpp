#include "MuchoCreatorHub.hpp"
#include <climits>
#include <chrono>
using namespace geode::prelude;
namespace {
std::string txt(matjson::Value const& v,char const* key){return v[key].asString().unwrapOr("");}
int num(matjson::Value const& v,char const* key){auto n=v[key].asInt().unwrapOr(0);if(!n)n=mucho::clans::positiveId(v[key].asString().unwrapOr(""));return n>0&&n<=INT_MAX?static_cast<int>(n):0;}
bool yes(matjson::Value const& v,char const* key){return v[key].asBool().unwrapOr(false);}
std::string clean(std::string value,size_t limit=160){for(auto& c:value)if(c=='<'||c=='>'||static_cast<unsigned char>(c)<32)c=' ';return mucho::emoji::shortenText(value,limit);}
std::string stateLabel(std::string const& state){if(state=="submitted")return "Waiting for review";if(state=="in_review")return "In review";if(state=="changes_requested")return "Changes requested";if(state=="approved")return "Recommended for rating";if(state=="rejected")return "Not recommended";if(state=="withdrawn")return "Withdrawn";return state;}
}
void MuchoCreatorHub::label(std::string value,float x,float y,float scale,float width){auto node=CCLabelBMFont::create(clean(std::move(value),400).c_str(),"bigFont.fnt");node->setPosition({x,y});node->limitLabelWidth(width,scale,.14f);m_body->addChild(node);}
void MuchoCreatorHub::button(char const* value,float x,float y,SEL_MenuHandler fn,int tag,float scale){auto sprite=ButtonSprite::create(value);auto b=mucho::button(sprite,this,fn,std::min(scale,105.f/sprite->getContentSize().width));b->setPosition({x,y});b->setTag(tag);b->setEnabled(!m_busy);auto menu=CCMenu::create();menu->setPosition({0,0});menu->addChild(b);m_body->addChild(menu);}
void MuchoCreatorHub::redraw(){if(m_queued||m_closed)return;m_queued=true;queueInMainThread([self=Ref(this)]{self->m_queued=false;if(!self->m_closed)self->render();});}
void MuchoCreatorHub::request(std::string action,std::vector<std::pair<std::string,std::string>> fields){
 if(m_closed||m_busy)return;auto a=GJAccountManager::get();auto origin=Mod::get()->getSettingValue<std::string>("server-url");while(!origin.empty()&&origin.back()=='/')origin.pop_back();
 if(!a||a->m_accountID!=m_account||a->m_GJP2.size()!=40||origin!=m_origin){m_notice="Log in, or reopen Creator Hub after changing account/server";redraw();return;}
 auto body="accountID="+std::to_string(m_account)+"&gjp2="+mucho::clans::encoded(a->m_GJP2.c_str())+"&gameVersion=22&action="+mucho::clans::encoded(action);
 for(auto const& [key,value]:fields)body+="&"+key+"="+mucho::clans::encoded(value);
 m_busy=true;m_notice="Connecting...";redraw();
 m_task.spawn("Mucho Creator Hub",web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false).header("Content-Type","application/x-www-form-urlencoded").bodyString(body).post(m_origin+"/extensions/creator-hub"),[this,action](web::WebResponse r){
  if(m_closed)return;m_busy=false;auto a=GJAccountManager::get();auto origin=Mod::get()->getSettingValue<std::string>("server-url");while(!origin.empty()&&origin.back()=='/')origin.pop_back();
  if(!a||a->m_accountID!=m_account||origin!=m_origin){m_notice="Account/server changed. Reopen Creator Hub.";redraw();return;}
  auto raw=r.string().unwrapOr("");if(raw.size()>131072){m_notice="Response too large";redraw();return;}auto parsed=r.json();
  if(!r.ok()||!parsed||!yes(parsed.unwrap(),"ok")){m_notice=r.code()==404?"Update MuchoCore and enable Creator Hub":parsed?txt(parsed.unwrap(),"error"):"Connection failed";if(m_notice.empty())m_notice="Creator Hub unavailable";redraw();return;}
  m_data=parsed.unwrap()["data"];m_notice="";m_review=yes(m_data,"can_review");
  if(action=="list"){m_more=yes(m_data,"has_next");m_slice=0;}else{m_view="detail";m_history=0;if(action!="detail"){m_draft.clear();m_input=nullptr;}}
  redraw();
 });
}
void MuchoCreatorHub::list(std::string view){if(m_busy)return;m_view=std::move(view);m_draft.clear();m_input=nullptr;m_page=1;m_slice=0;m_data=matjson::Value::object();request("list",{{"view",m_view},{"page","1"}});}
void MuchoCreatorHub::write(std::string action,std::vector<std::pair<std::string,std::string>> fields){fields.push_back({"requestID",std::to_string(num(m_data,"id"))});fields.push_back({"revision",std::to_string(num(m_data,"revision"))});request(std::move(action),std::move(fields));}
void MuchoCreatorHub::render(){
 if(m_input)m_draft=m_input->getString();m_body->removeAllChildren();m_input=nullptr;
 button("My Requests",85,278,menu_selector(MuchoCreatorHub::onMine));button("My Levels",207,278,menu_selector(MuchoCreatorHub::onLevels));
 if(m_review){button("Queue",319,278,menu_selector(MuchoCreatorHub::onQueue));button("Archive",409,278,menu_selector(MuchoCreatorHub::onArchive));}
 label(m_notice,235,15,.24f,438);
 if(m_view=="submit"){
  label("Submit level #"+std::to_string(m_selectedLevel),235,231,.47f);label("Tell the reviewer what makes your level special",235,192,.29f);
  m_input=TextInput::create(380,"Message to reviewer (optional)");m_input->setMaxCharCount(1000);m_input->setString(m_draft);m_input->setPosition({235,152});m_body->addChild(m_input);
  label("Public levels only / 3 active requests / 5 submissions per day",235,112,.25f);
  button("Back",148,61,menu_selector(MuchoCreatorHub::onBack));button("Send Request",318,61,menu_selector(MuchoCreatorHub::onSubmit));return;
 }
 if(m_view=="detail"){
  auto state=txt(m_data,"state");label(txt(m_data,"level_name"),235,243,.48f);label(stateLabel(state)+" / v"+std::to_string(num(m_data,"level_version"))+" / "+txt(m_data,"creator_name"),235,218,.28f);
  label(yes(m_data,"level_changed")?"Level updated - resubmit before review":num(m_data,"stars")?"Rated: "+std::to_string(num(m_data,"stars"))+" stars":"Review applies to the submitted version",235,196,.25f);
  label(txt(m_data,"author_note"),235,176,.27f);button("Read",431,176,menu_selector(MuchoCreatorHub::onReadAuthor),0,.20f);
  auto history=m_data["history"];int size=history.isArray()?static_cast<int>(history.size()):0;m_history=std::clamp(m_history,0,std::max(0,size-1));
  if(size){auto const& e=history[m_history];label(txt(e,"actor_name")+" / "+stateLabel(txt(e,"action"))+" / "+txt(e,"created_at"),235,153,.23f);label(txt(e,"note"),235,129,.29f);button("Read",431,129,menu_selector(MuchoCreatorHub::onReadFeedback),0,.20f);}
  button("<",183,104,menu_selector(MuchoCreatorHub::onHistoryPrev),0,.23f);label(std::to_string(size?m_history+1:0)+" / "+std::to_string(size),235,104,.22f,65);button(">",287,104,menu_selector(MuchoCreatorHub::onHistoryNext),0,.23f);
  if(m_review&&!yes(m_data,"mine")&&state=="submitted")button("Take Review",235,72,menu_selector(MuchoCreatorHub::onClaim));
  if(m_review&&yes(m_data,"assigned_to_me")&&state=="in_review"){
   m_input=TextInput::create(390,"Feedback to creator");m_input->setMaxCharCount(1000);m_input->setString(m_draft);m_input->setPosition({235,78});m_body->addChild(m_input);
   if(!yes(m_data,"level_changed")){button("Changes",171,45,menu_selector(MuchoCreatorHub::onDecision),0,.27f);button("Recommend",265,45,menu_selector(MuchoCreatorHub::onDecision),1,.27f);button("Reject",361,45,menu_selector(MuchoCreatorHub::onDecision),2,.27f);}
   button("Release",409,105,menu_selector(MuchoCreatorHub::onRelease),0,.25f);
  }
  if(yes(m_data,"mine")&&(state=="submitted"||state=="in_review"||state=="changes_requested"))button("Withdraw",374,72,menu_selector(MuchoCreatorHub::onWithdraw),0,.26f);
  if(yes(m_data,"mine")&&(yes(m_data,"level_changed")||state=="changes_requested")){button("Resubmit",235,72,menu_selector(MuchoCreatorHub::onRow),num(m_data,"level_id"),.27f);}
  button("Back",66,45,menu_selector(MuchoCreatorHub::onBack),0,.28f);button("Level",66,77,menu_selector(MuchoCreatorHub::onLevel),0,.28f);button("Refresh",409,244,menu_selector(MuchoCreatorHub::onRefresh),0,.24f);return;
 }
 auto items=m_data["items"];int size=items.isArray()?static_cast<int>(items.size()):0;
 for(int n=0;n<3&&m_slice*3+n<size;++n){auto const& row=items[m_slice*3+n];float y=221-n*53;
  label(txt(row,m_view=="levels"?"name":"level_name"),175,y,.38f,280);
  label(m_view=="levels"?"Level #"+std::to_string(num(row,"level_id"))+(num(row,"stars")?" / Rated":" / Published"):stateLabel(txt(row,"state"))+(yes(row,"level_changed")?" / Updated":""),175,y-19,.25f,280);
  button(m_view=="levels"?"Submit":"Details",389,y-8,menu_selector(MuchoCreatorHub::onRow),num(row,m_view=="levels"?"level_id":"id"),.31f);
 }
 if(size==0&&!m_busy)label(m_view=="levels"?"Upload a public level first":"No requests here yet",235,194,.38f);
 button("<",129,64,menu_selector(MuchoCreatorHub::onPrev));label("Page "+std::to_string((m_page-1)*4+m_slice+1),235,64,.3f,100);button(">",340,64,menu_selector(MuchoCreatorHub::onNext));
 button("Refresh",235,35,menu_selector(MuchoCreatorHub::onRefresh),0,.27f);
}
void MuchoCreatorHub::onMine(CCObject*){list("mine");}void MuchoCreatorHub::onLevels(CCObject*){list("levels");}void MuchoCreatorHub::onQueue(CCObject*){list("queue");}void MuchoCreatorHub::onArchive(CCObject*){list("archive");}
void MuchoCreatorHub::onRow(CCObject* o){if(m_busy)return;auto id=static_cast<CCNode*>(o)->getTag();if(id<=0)return;if(m_view=="levels"||m_view=="detail"){m_back=m_view;m_draft.clear();m_input=nullptr;m_selectedLevel=id;m_view="submit";m_notice="";redraw();}else{m_back=m_view;request("detail",{{"requestID",std::to_string(id)}});}}
void MuchoCreatorHub::onSubmit(CCObject*){request("submit",{{"levelID",std::to_string(m_selectedLevel)},{"note",m_input?m_input->getString():""}});}
void MuchoCreatorHub::onPrev(CCObject*){if(m_busy)return;if(m_slice>0){--m_slice;redraw();}else if(m_page>1){--m_page;request("list",{{"view",m_view},{"page",std::to_string(m_page)}});}}
void MuchoCreatorHub::onNext(CCObject*){if(m_busy)return;auto items=m_data["items"];auto size=items.isArray()?static_cast<int>(items.size()):0;if((m_slice+1)*3<size){++m_slice;redraw();}else if(m_more){++m_page;request("list",{{"view",m_view},{"page",std::to_string(m_page)}});}}
void MuchoCreatorHub::onRefresh(CCObject*){if(m_view=="detail")request("detail",{{"requestID",std::to_string(num(m_data,"id"))}});else request("list",{{"view",m_view},{"page",std::to_string(m_page)}});}
void MuchoCreatorHub::onBack(CCObject*){if(m_view=="submit"&&m_back=="detail"){m_view="detail";redraw();}else list(m_back=="detail"?"mine":m_back);}
void MuchoCreatorHub::onClaim(CCObject*){write("claim");}void MuchoCreatorHub::onRelease(CCObject*){write("release");}void MuchoCreatorHub::onWithdraw(CCObject*){write("withdraw");}
void MuchoCreatorHub::onDecision(CCObject* o){char const* states[]={"changes_requested","approved","rejected"};auto n=static_cast<CCNode*>(o)->getTag();if(n<0||n>2)return;write("decide",{{"state",states[n]},{"note",m_input?m_input->getString():""}});}
void MuchoCreatorHub::onReadFeedback(CCObject*){auto h=m_data["history"];if(h.isArray()&&m_history<static_cast<int>(h.size()))FLAlertLayer::create("Reviewer feedback",clean(txt(h[m_history],"note"),2000),"OK")->show();}
void MuchoCreatorHub::onReadAuthor(CCObject*){FLAlertLayer::create("Creator message",clean(txt(m_data,"author_note"),2000),"OK")->show();}
void MuchoCreatorHub::onHistoryPrev(CCObject*){if(m_history>0){--m_history;redraw();}}void MuchoCreatorHub::onHistoryNext(CCObject*){auto h=m_data["history"];if(h.isArray()&&m_history+1<static_cast<int>(h.size())){++m_history;redraw();}}
void MuchoCreatorHub::onLevel(CCObject*){auto id=num(m_data,"level_id");if(id<=0)return;auto scene=LevelBrowserLayer::scene(GJSearchObject::create(static_cast<SearchType>(0),std::to_string(id)));if(scene){onClose(nullptr);CCDirector::get()->replaceScene(CCTransitionFade::create(.3f,scene));}}
bool MuchoCreatorHub::init(std::string origin){if(!Popup::init(470,330))return false;m_origin=std::move(origin);auto a=GJAccountManager::get();m_account=a?a->m_accountID:0;setTitle("Mucho | Creator Hub");auto size=CCDirector::get()->getWinSize();m_mainLayer->setScale(mucho::layout::popupScale(size.width,size.height,470,330));m_body=CCNode::create();m_mainLayer->addChild(m_body);render();list("mine");mucho::motion::panel(m_mainLayer);return true;}
void MuchoCreatorHub::onClose(CCObject* o){if(m_closed)return;m_closed=true;m_task.cancel();Popup::onClose(o);}void MuchoCreatorHub::onExit(){m_closed=true;m_task.cancel();Popup::onExit();}
MuchoCreatorHub* MuchoCreatorHub::create(std::string origin){auto p=new MuchoCreatorHub;if(p->init(std::move(origin))){p->autorelease();return p;}delete p;return nullptr;}
