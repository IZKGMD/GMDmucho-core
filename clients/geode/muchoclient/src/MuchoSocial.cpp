#include "MuchoSocial.hpp"
#include "MuchoAppearanceRules.hpp"
#include <Geode/modify/MenuLayer.hpp>
#include <Geode/modify/PlayLayer.hpp>
#include <Geode/modify/LevelEditorLayer.hpp>
#include <Geode/loader/GameEvent.hpp>
#include <chrono>
using namespace geode::prelude;
namespace mucho::social {
namespace {
std::string state="menu";int level=0;bool exiting=false;
std::string origin(){auto s=Mod::get()->getSettingValue<std::string>("server-url");while(!s.empty()&&s.back()=='/')s.pop_back();return s;}
bool valid(std::string const& s){return appearance::validOrigin(s);}
std::string clean(std::string s){for(auto& c:s)if(c=='<'||c=='>'||static_cast<unsigned char>(c)<32)c=' ';return emoji::shortenText(s,70);}
std::string text(matjson::Value const& v,char const* key){return v[key].asString().unwrapOr("");}
int number(matjson::Value const& v,char const* key){auto n=v[key].asInt().unwrapOr(0);if(!n)n=clans::positiveId(v[key].asString().unwrapOr(""));return n>0&&n<=INT_MAX?static_cast<int>(n):0;}
std::string body(std::string action){auto a=GJAccountManager::get();if(!a||a->m_accountID<=0||a->m_GJP2.size()!=40)return "";return "accountID="+std::to_string(a->m_accountID)+"&gjp2="+clans::encoded(a->m_GJP2.c_str())+"&action="+clans::encoded(action);}
class Presence:public CCNode {
 async::TaskHolder<web::WebResponse> task;bool busy=false;std::string last;int lastAccount=0;double due=0;
 void tick(float){
  if(exiting||busy)return;auto now=std::chrono::duration<double>(std::chrono::steady_clock::now().time_since_epoch()).count();
  auto a=GJAccountManager::get();auto account=a?a->m_accountID:0;auto signature=state+std::to_string(level)+origin()+std::to_string(account);
  if(now<due)return;if(signature==last&&now<due+25)return;
  auto data=body("heartbeat");auto url=origin();if(data.empty()||!valid(url))return;
  busy=true;last=signature;lastAccount=account;due=now+5;
  data+="&activity="+state+"&levelID="+std::to_string(level);
  task.spawn("Mucho presence",web::WebRequest().timeout(std::chrono::seconds(8)).followRedirects(false).header("Content-Type","application/x-www-form-urlencoded").bodyString(data).post(url+"/api/social/live"),[this](web::WebResponse r){busy=false;if(!r.ok())due=std::chrono::duration<double>(std::chrono::steady_clock::now().time_since_epoch()).count()+60;});
 }
public:static void install(){auto p=new Presence;if(p->init()){CCDirector::get()->getScheduler()->scheduleSelector(schedule_selector(Presence::tick),p,5.f,false);}p->release();}
};
class SocialPopup:public Popup {
 CCNode* content=nullptr;CCMenu* menu=nullptr;TextInput* input=nullptr;CCLabelBMFont* status=nullptr;
 async::TaskHolder<web::WebResponse> task;bool closed=false,busy=false;std::string view="friends",url,notice;matjson::Value data;
 std::vector<matjson::Value> rows;int target=0,roomID=0,page=0;
 void label(std::string s,float x,float y,float size=.34f,float width=350){auto l=CCLabelBMFont::create(clean(s).c_str(),"bigFont.fnt");l->setPosition({x,y});l->limitLabelWidth(width,size,.15f);content->addChild(l);}
 void button(char const* title,float x,float y,SEL_MenuHandler fn,int tag=0,float scale=.37f){auto b=mucho::button(ButtonSprite::create(title),this,fn,scale);b->setTag(tag);b->setPosition({x,y});b->setEnabled(!busy);menu->addChild(b);}
 void redraw(){queueInMainThread([self=Ref(this)]{if(!self->closed)self->render();});}
 void request(std::string op,std::vector<std::pair<std::string,std::string>> fields={}){
  if(busy||closed)return;auto payload=body(op);if(payload.empty()){notice="Log into your GDPS account first";redraw();return;}
  if(!valid(url)){notice="Configure an HTTPS server URL";redraw();return;}for(auto const& [key,value]:fields)payload+="&"+key+"="+clans::encoded(value);
  auto account=GJAccountManager::get()->m_accountID;busy=true;notice="Loading...";redraw();
  task.spawn("Mucho social",web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false).header("Content-Type","application/x-www-form-urlencoded").bodyString(payload).post(url+"/api/social/live"),[this,op,account](web::WebResponse r){
   if(closed)return;busy=false;if(origin()!=url||GJAccountManager::get()->m_accountID!=account){notice="Account or server changed - reopen Social";redraw();return;}
   if(r.string().unwrapOr("").size()>524288){notice="Social response too large";redraw();return;}
   auto parsed=r.json();if(!parsed||!r.ok()||!parsed.unwrap()["ok"].asBool().unwrapOr(false)){notice=r.code()==404?"Update MuchoCore for Social":parsed?text(parsed.unwrap(),"error"):"Connection failed";redraw();return;}
   data=parsed.unwrap()["data"];notice="";
   if(op=="profile"||op=="react")view="profile";
   if(op=="room-create"||op=="room"||op=="accept"){view="room";roomID=number(data,"id");}
   if(op=="invite")notice="Invitation sent";
   if(op=="leave"||op=="decline"){view="invites";request("invites");return;}
   redraw();
  });
 }
 void render(){
  content->removeAllChildren();menu=CCMenu::create();menu->setPosition({0,0});content->addChild(menu);input=nullptr;
  button("Friends",62,242,menu_selector(SocialPopup::onFriends));button("Profile",161,242,menu_selector(SocialPopup::onProfile));button("Invites",264,242,menu_selector(SocialPopup::onInvites));button("Room",361,242,menu_selector(SocialPopup::onRoom));
  status=CCLabelBMFont::create(clean(notice).c_str(),"bigFont.fnt");status->setPosition({210,18});status->limitLabelWidth(390,.25f,.15f);content->addChild(status);
  rows.clear();auto list=data[view=="friends"?"friends":view=="invites"?"invites":view=="room"?"members":"achievements"];
  if(list.isArray())for(auto const& row:list)rows.push_back(row);
  if(view=="profile"){
   label(text(data,"username")+" / "+text(data,"activity"),210,204,.43f);target=number(data,"account_id");
   for(int n=0;n<3&&page*3+n<static_cast<int>(rows.size());++n){auto i=page*3+n;auto& row=rows[i];float y=162-n*34;
    label(text(row,"id"),105,y,.31f,175);int total=0;if(row["reactions"].isArray())for(auto const& reaction:row["reactions"])total+=number(reaction,"total");label(std::to_string(total),211,y,.26f,24);
    for(int k=0;k<4;++k)button(k==0?"+1":k==1?"Fire":k==2?"Heart":"Clap",249+k*44,y,menu_selector(SocialPopup::onReact),i*4+k,.24f);
   }
   if(rows.empty())label("No server-confirmed milestones yet",210,145,.31f);
  } else {
   if(view=="room")label(roomID?"Room #"+std::to_string(roomID)+" / level "+std::to_string(number(data,"level_id")):"Create a room with a published level ID",210,204,.33f);
   for(int n=0;n<4&&page*4+n<static_cast<int>(rows.size());++n){auto i=page*4+n;auto& row=rows[i];float y=190-n*30;
    label(text(row,"username")+(view=="friends"?" / "+text(row,"activity"):""),139,y,.31f,240);
    if(view=="friends")button("View",333,y,menu_selector(SocialPopup::onFriend),number(row,"account_id"),.31f);
    if(view=="invites"){button("Join",288,y,menu_selector(SocialPopup::onAccept),number(row,"room_id"),.29f);button("No",363,y,menu_selector(SocialPopup::onDecline),number(row,"room_id"),.29f);}
   }
   if(rows.empty()&&view!="room")label(view=="friends"?"No friends to show":"No pending invitations",210,159,.35f);
  }
  button("<",165,70,menu_selector(SocialPopup::onPrev),0,.29f);label(std::to_string(page+1),210,70,.26f,40);button(">",255,70,menu_selector(SocialPopup::onNext),0,.29f);
  input=TextInput::create(165,view=="room"?(roomID?"Friend account ID":"Published level ID"):"Account ID");input->setCommonFilter(CommonFilter::Uint);input->setMaxCharCount(10);input->setPosition({111,42});content->addChild(input);
  button(view=="room"?(roomID?"Invite":"Create"):"View",259,42,menu_selector(SocialPopup::onInput),0,.34f);
  if(view=="room"&&roomID){button("Level",339,42,menu_selector(SocialPopup::onLevel),0,.3f);button("Leave",381,78,menu_selector(SocialPopup::onLeave),0,.27f);}
 }
 void onFriends(CCObject*){view="friends";page=0;request("friends");}
 void onProfile(CCObject*){page=0;request("profile");}
 void onInvites(CCObject*){view="invites";page=0;request("invites");}
 void onRoom(CCObject*){page=0;if(roomID)request("room",{{"roomID",std::to_string(roomID)}});else{view="room";data=matjson::Value::object();redraw();}}
 void onFriend(CCObject* o){page=0;request("profile",{{"targetID",std::to_string(static_cast<CCNode*>(o)->getTag())}});}
 void onAccept(CCObject* o){page=0;request("accept",{{"roomID",std::to_string(static_cast<CCNode*>(o)->getTag())}});}
 void onDecline(CCObject* o){request("decline",{{"roomID",std::to_string(static_cast<CCNode*>(o)->getTag())}});}
 void onLeave(CCObject*){auto id=roomID;roomID=0;request("leave",{{"roomID",std::to_string(id)}});}
 void onInput(CCObject*){auto id=input?clans::positiveId(input->getString()):0;if(!id){notice="Enter a positive ID";redraw();return;}
  if(view=="room")request(roomID?"invite":"room-create",{{roomID?"targetID":"levelID",std::to_string(id)},{"roomID",std::to_string(roomID)}});
  else{page=0;request("profile",{{"targetID",std::to_string(id)}});}}
 void onReact(CCObject* o){auto tag=static_cast<CCNode*>(o)->getTag();if(tag<0||tag/4>=static_cast<int>(rows.size()))return;char const* reactions[]={"like","fire","heart","clap"};auto emotion=std::string(reactions[tag%4]);auto& row=rows[tag/4];if(text(row,"my_reaction")==emotion)emotion="";
  request("react",{{"targetID",std::to_string(target)},{"achievement",text(row,"id")},{"reaction",emotion}});}
 void onLevel(CCObject*){auto id=number(data,"level_id");if(id<=0)return;auto search=GJSearchObject::create(static_cast<SearchType>(0),std::to_string(id));auto scene=LevelBrowserLayer::scene(search);if(scene){onClose(nullptr);CCDirector::get()->replaceScene(CCTransitionFade::create(.3f,scene));}}
 void onPrev(CCObject*){if(page){--page;redraw();}}void onNext(CCObject*){auto size=view=="profile"?3:4;if((page+1)*size<static_cast<int>(rows.size())){++page;redraw();}}
 void onClose(CCObject* s) override{closed=true;task.cancel();Popup::onClose(s);}
 bool init(int account){if(!Popup::init(420,284))return false;setTitle("Mucho Social");url=origin();content=CCNode::create();m_mainLayer->addChild(content);render();if(account>0)request("profile",{{"targetID",std::to_string(account)}});else request("friends");return true;}
public:static SocialPopup* create(int account){auto p=new SocialPopup;if(p->init(account)){p->autorelease();return p;}delete p;return nullptr;}
};
}
void activity(std::string s,int id){state=std::move(s);level=std::max(0,id);}
void show(int account){if(auto p=SocialPopup::create(account))p->show();}
$on_game(Loaded){Presence::install();}
$on_game(Exiting){exiting=true;}
}
class $modify(MuchoPresenceMenu,MenuLayer){bool init(){if(!MenuLayer::init())return false;mucho::social::activity("menu");return true;}};
class $modify(MuchoPresencePlay,PlayLayer){bool init(GJGameLevel* l,bool r,bool d){if(!PlayLayer::init(l,r,d))return false;mucho::social::activity("playing",l?static_cast<int>(l->m_levelID):0);return true;}void onExit(){mucho::social::activity(LevelEditorLayer::get()?"editor":"menu");PlayLayer::onExit();}};
class $modify(MuchoPresenceEditor,LevelEditorLayer){bool init(GJGameLevel* l,bool noUI){if(!LevelEditorLayer::init(l,noUI))return false;mucho::social::activity("editor");return true;}};
static bool muchoClientReady=false;
$on_mod(Loaded){muchoClientReady=true;}
extern "C" GEODE_EXPORT int MuchoClientHealth_v1(){return muchoClientReady?1:0;}
