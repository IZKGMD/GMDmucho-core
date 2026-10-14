#include "MuchoMedia.hpp"
#include "MuchoAttachmentViewport.hpp"
#include "MuchoProfilePopup.hpp"
#include <Geode/modify/GJWriteMessagePopup.hpp>
#include <Geode/modify/GJMessagePopup.hpp>
#include <Geode/binding/GJUserMessage.hpp>
#include <Geode/ui/TextInput.hpp>
#include <climits>

using namespace geode::prelude;
namespace mucho::media {
namespace {
std::string text(matjson::Value const& v,char const* key){return v[key].asString().unwrapOr("");}
int number(matjson::Value const& v,char const* key){auto n=v[key].asInt().unwrapOr(0);if(!n)n=clans::positiveId(v[key].asString().unwrapOr(""));return n>0&&n<=INT_MAX?static_cast<int>(n):0;}
std::string clean(std::string s,size_t limit=100){for(auto& c:s)if(static_cast<unsigned char>(c)<32||c=='<'||c=='>')c=' ';return emoji::shortenText(s,limit);}

// Private attachments deliberately bypass the public profile texture cache.
// Every fetch proves the current account credential and room/message access.
class MediaPopup final:public Popup {
 std::string mode,scope,url;int account=0,target=0,page=0;
 bool closed=false,busy=false,images=false,ready=false,modal=false;
 CCNode* content=nullptr;CCMenu* menu=nullptr;CCLabelBMFont* statusLabel=nullptr;
 TextInput* caption=nullptr;TextInput* subject=nullptr;
 AttachmentViewport* viewport=nullptr;
 LazySprite* image=nullptr;std::vector<uint8_t> bytes;
 std::vector<matjson::Value> rows;std::function<void()> accepted;
 async::TaskHolder<web::WebResponse> requestTask;
 async::TaskHolder<file::PickResult> picker;
 async::TaskHolder<std::vector<uint8_t>> reader;
 async::TaskHolder<std::string> encoder;
 std::vector<CCMenuItemSpriteExtra*> buttons;
 unsigned generation=0;
 void status(std::string s){statusLabel->setString(clean(std::move(s),155).c_str());statusLabel->limitLabelWidth(370,.23f,0);}
 bool session(){std::string token;if(closed)return false;if(url!=profile::origin()||!profile::credential(account,token)){status("Account or server changed. Close and reopen this window");return false;}return true;}
 void enable(){for(auto b:buttons)b->setEnabled(!closed&&!busy&&!modal);}
 CCMenuItemSpriteExtra* button(char const* title,float x,float y,SEL_MenuHandler selector,int tag=0,float scale=.32f){auto b=mucho::button(ButtonSprite::create(title),this,selector,scale);b->setPosition({x,y});b->setTag(tag);menu->addChild(b);buttons.push_back(b);return b;}
 void label(std::string value,float x,float y,float width=320,float scale=.30f){auto l=CCLabelBMFont::create(clean(std::move(value)).c_str(),"bigFont.fnt");l->setPosition({x,y});l->limitLabelWidth(width,scale,0);content->addChild(l);}
 void reset(){++generation;content->removeAllChildren();buttons.clear();menu=CCMenu::create();menu->setPosition({0,0});content->addChild(menu);caption=subject=nullptr;image=nullptr;viewport=nullptr;}
 void request(std::string action,std::string extra,std::function<void(matjson::Value const&)> done){
  if(busy||modal||!session())return;std::string token;if(!profile::credential(account,token))return;
  busy=true;enable();status("Connecting...");auto stamp=generation;
  auto body="accountID="+std::to_string(account)+"&gameVersion=22&gjp2="+token+"&action="+clans::encoded(action)+extra;
  requestTask.spawn("Mucho private media",web::WebRequest().timeout(std::chrono::seconds(35)).followRedirects(false).header("Content-Type","application/x-www-form-urlencoded").bodyString(body).post(url+"/api/media"),[this,stamp,done=std::move(done)](web::WebResponse r){
   if(closed||stamp!=generation)return;busy=false;enable();if(!session())return;
   if(r.data().size()>131072){status("Invalid media response");return;}auto parsed=r.json();
   if(!r.ok()||!parsed||!parsed.unwrap()["ok"].asBool().unwrapOr(false)){status(r.code()==404||r.code()==503?"Images/chat unavailable. Ask the owner to update MuchoCore":parsed?text(parsed.unwrap(),"error"):"Connection failed. Try again");return;}
   done(parsed.unwrap()["data"]);
  });
 }
 void decode(std::vector<uint8_t> data,bool png){
  ready=false;if(image)image->removeFromParent();image=LazySprite::create({344,mode=="compose"?100.f:150.f},false);image->setPosition({202,mode=="compose"?190.f:172.f});image->setVisible(false);if(mode=="view"){viewport=AttachmentViewport::create({344,150});viewport->setPosition({30,97});content->addChild(viewport);viewport->image(image);}else content->addChild(image);
  auto sprite=image;auto weak=WeakRef(this);auto stamp=generation;sprite->retain();
  sprite->setLoadCallback([weak,stamp,sprite](Result<> result){geode::queueInMainThread([sprite]{sprite->release();});auto self=weak.lock();if(!self||self->closed||self->generation!=stamp||self->image!=sprite)return;
   if(!self->session())return;if(!result){self->status("Image could not be decoded");return;}
   auto size=sprite->getTexture()->getContentSize();if(size.width<=0||size.height<=0){self->status("Invalid image size");return;}
   if(self->viewport)self->viewport->loaded();else sprite->setScale(std::min(344.f/size.width,(self->mode=="compose"?100.f:150.f)/size.height));sprite->setVisible(true);self->ready=true;self->status(self->mode=="compose"?"Preview ready. Press Send to upload":"Zoom with +/- and drag to read details");
  });sprite->loadFromData(std::move(data),png?CCImage::kFmtPng:CCImage::kFmtJpg);
 }
 void onPick(CCObject*){
  if(busy||modal||!images||!session())return;busy=true;enable();status("Choose a JPG or PNG up to 4 MB");auto stamp=generation;
  picker.spawn("Choose message image",file::pick(file::PickMode::OpenFile,file::FilePickOptions{.filters={{.description="JPG / PNG",.files={"*.jpg","*.jpeg","*.png"}}}}),[this,stamp](file::PickResult result){
   if(closed||stamp!=generation)return;if(!session()){busy=false;enable();return;}if(!result||!result.unwrap()){busy=false;enable();status("Selection cancelled");return;}auto path=*result.unwrap();
   reader.spawn("Read message image",[path]()->arc::Future<std::vector<uint8_t>>{std::error_code ec;auto size=std::filesystem::file_size(path,ec);if(ec||size<24||size>profile::MAX_UPLOAD_BYTES)co_return std::vector<uint8_t>{};std::ifstream f(path,std::ios::binary);std::vector<uint8_t> data(size);if(!f.read(reinterpret_cast<char*>(data.data()),static_cast<std::streamsize>(data.size())))data.clear();co_return data;},[this,stamp](std::vector<uint8_t> data){
    if(closed||stamp!=generation)return;busy=false;enable();if(!session())return;auto size=profile::imageSize(data);if(!size){status("Use a readable JPG/PNG up to 4 MB and 12 megapixels");return;}bytes=std::move(data);status("Loading preview...");decode(bytes,size->png);
   });
  });
 }
 void upload(){
  if(busy||modal||!session()||!images)return;if(bytes.empty()||!ready){status("Choose an image and wait for its preview");return;}
  auto title=subject?subject->getString():"Image";auto note=caption?caption->getString():"";
  if(title.size()>160||note.size()>1000){status("Shorten the subject or caption");return;}
  busy=true;enable();status("Preparing upload...");auto stamp=generation;
  encoder.spawn("Encode message image",[data=bytes]()->arc::Future<std::string>{co_return clans::encoded(base64::encode(data,base64::Base64Variant::Normal));},[this,stamp,title,note](std::string encoded){
   if(closed||stamp!=generation)return;busy=false;enable();if(!session())return;
   request("upload","&scope="+scope+"&targetID="+std::to_string(target)+"&subject="+clans::encoded(title)+"&caption="+clans::encoded(note)+"&imageData="+encoded,[this](matjson::Value const& v){
    auto state=text(v,"state");if(state=="rejected"){status("Image rejected by moderation. Choose another image");return;}
    if(state!="approved"&&state!="pending"){status("Invalid upload response");return;}
    // A pending upload is complete but remains invisible until approved.
    bytes.clear();if(accepted){auto callback=std::move(accepted);callback();}auto message=state=="pending"?"Uploaded for review. It will appear after approval. Check My images.":"Image sent.";
    onClose(nullptr);FLAlertLayer::create("Mucho images",message,"OK")->show();
   });
  });
 }
 void onSend(CCObject*){if(mode=="compose")upload();else if(mode=="chat"){
  auto value=caption?caption->getString():"";if(value.empty()){status("Write a message");return;}request("chat-send","&roomID="+std::to_string(target)+"&text="+clans::encoded(value),[this](matjson::Value const& v){chatData(v);});
 }}
 void onPhoto(CCObject*){if(busy||modal||!session()||!images)return;auto weak=WeakRef(this);compose("room",target,caption?caption->getString():"","",[weak]{if(auto self=weak.lock();self&&!self->closed)self->reload();});}
 void onView(CCObject* o){if(busy||modal||!session())return;auto id=static_cast<CCNode*>(o)->getTag();if(id>0)open("view",id);}
 void onRemove(CCObject* o){if(busy||modal||!session())return;auto id=static_cast<CCNode*>(o)->getTag();modal=true;enable();auto weak=WeakRef(this);
  createQuickPopup("Remove image","Remove this uploaded image?", "Cancel","Remove",[weak,id](FLAlertLayer*,bool yes){if(auto self=weak.lock();self&&!self->closed){self->modal=false;self->enable();if(yes)self->request("remove","&imageID="+std::to_string(id),[weak](matjson::Value const&){if(auto p=weak.lock())p->reload();});}},true,true);
 }
 void onZoom(CCObject* o){if(!viewport||!ready||!session())return;auto tag=static_cast<CCNode*>(o)->getTag();if(tag==0)viewport->fit();else viewport->zoom(tag>0?1.6f:1.f/1.6f);}
 void onReport(CCObject* o){if(busy||modal||!session())return;char const* reasons[]={"nsfw","violence","child_safety","other"};auto index=static_cast<CCNode*>(o)->getTag();if(index<0||index>3)return;auto reason=std::string(reasons[index]);modal=true;enable();auto weak=WeakRef(this);
  createQuickPopup("Report image","Hide this image and send it to the server moderators?","Cancel","Report",[weak,reason](FLAlertLayer*,bool yes){if(auto self=weak.lock();self&&!self->closed){self->modal=false;self->enable();if(yes)self->request("report","&imageID="+std::to_string(self->target)+"&reason="+reason,[weak](matjson::Value const&){if(auto p=weak.lock()){p->onClose(nullptr);FLAlertLayer::create("Image reported","Hidden until a moderator reviews it.","OK")->show();}});}},true,true);
 }
 void onRefresh(CCObject*){reload();}void onPrev(CCObject*){if(!busy&&!modal&&page>0){--page;listing();}}void onNext(CCObject*){if(!busy&&!modal&&(page+1)*4<static_cast<int>(rows.size())){++page;listing();}}
 void listing(){
  auto draft=caption?caption->getString():"";reset();page=std::clamp(page,0,rows.empty()?0:static_cast<int>((rows.size()-1)/4));
  for(int n=0;n<4&&page*4+n<static_cast<int>(rows.size());++n){auto const& row=rows[page*4+n];float y=222-n*36;
   if(mode=="chat"){
    label(text(row,"username")+": "+text(row,"content"),166,y,278,.28f);auto id=number(row,"media_id");if(id&&images)button("Image",354,y,menu_selector(MediaPopup::onView),id,.28f);
   }else{
    auto id=number(row,"id");auto state=mode=="mine"?text(row,"state"):"approved";label("#"+std::to_string(id)+" / "+state,146,y,225,.28f);
    if(state=="approved")button("View",290,y,menu_selector(MediaPopup::onView),id,.29f);
    if(mode=="mine")button("Remove",355,y,menu_selector(MediaPopup::onRemove),id,.25f);
   }
  }
  if(rows.empty())label(mode=="chat"?"No messages yet":"No images to show",202,175);
  button("<",142,82,menu_selector(MediaPopup::onPrev));label(std::to_string(page+1),202,82,35,.26f);button(">",262,82,menu_selector(MediaPopup::onNext));button("Refresh",352,82,menu_selector(MediaPopup::onRefresh),0,.27f);
  if(mode=="chat"){
   caption=TextInput::create(240,"Message");caption->setFilter("");caption->setMaxCharCount(240);caption->setString(draft);caption->setPosition({144,46});content->addChild(caption);
   button("Send",307,46,menu_selector(MediaPopup::onSend),0,.32f);if(images)button("Photo",369,46,menu_selector(MediaPopup::onPhoto),0,.29f);
  }enable();
 }
 void chatData(matjson::Value const& data){images=data["images_enabled"].asBool().unwrapOr(false);rows.clear();if(auto a=data["messages"].asArray())for(auto const& row:a.unwrap())rows.push_back(row);if(caption)caption->setString("");page=rows.empty()?0:static_cast<int>((rows.size()-1)/4);listing();status(text(data,"moderation")=="local"?"Local server rules / images screened offline":images?"Room chat / photos enabled":"Room chat / photos disabled by server");}
 void reload(){
  if(busy||modal||!session())return;
  if(mode=="chat"){request("chat","&roomID="+std::to_string(target),[this](matjson::Value const& v){chatData(v);});return;}
  request(mode=="mine"?"mine":"attachments",mode=="mine"?"":"&messageID="+std::to_string(target),[this](matjson::Value const& v){rows.clear();if(auto a=v["images"].asArray())for(auto const& row:a.unwrap())rows.push_back(row);listing();status(mode=="mine"?"Your uploads / pending files stay hidden":"Private message attachments");});
 }
 void loadImage(){
  if(busy||!session())return;std::string token;if(!profile::credential(account,token))return;busy=true;enable();status("Loading private image...");auto stamp=generation;
  requestTask.spawn("Download private image",web::WebRequest().timeout(std::chrono::seconds(20)).followRedirects(false).header("Content-Type","application/x-www-form-urlencoded").bodyString("accountID="+std::to_string(account)+"&gameVersion=22&gjp2="+token+"&action=image&imageID="+std::to_string(target)).post(url+"/api/media"),[this,stamp](web::WebResponse r){
   if(closed||stamp!=generation)return;busy=false;enable();if(!session())return;auto data=r.data();
   if(!r.ok()||data.size()>256*1024){status("Image unavailable or access revoked");return;}auto dimensions=profile::imageSize(data);if(!dimensions||dimensions->width>2048||dimensions->height>2048){status("Invalid image");return;}decode(std::move(data),dimensions->png);
  });
 }
 void watchSession(float){if(closed)return;std::string token;if(url!=profile::origin()||!profile::credential(account,token)){if(image)image->setVisible(false);requestTask.cancel();picker.cancel();reader.cancel();encoder.cancel();bytes.clear();ready=false;status("Account or server changed. Close and reopen this window");}}
 void stop(){closed=true;++generation;requestTask.cancel();picker.cancel();reader.cancel();encoder.cancel();bytes.clear();}
 void onClose(CCObject* o)override{stop();Popup::onClose(o);}void onExit()override{stop();Popup::onExit();}
 bool init(std::string view,int id,std::string conversation,std::string note,std::string title,std::function<void()> callback){
  if(!Popup::init(404,300))return false;mode=std::move(view);scope=std::move(conversation);target=id;accepted=std::move(callback);url=profile::origin();auto manager=GJAccountManager::get();account=manager?manager->m_accountID:0;
  schedule(schedule_selector(MediaPopup::watchSession),1.f);
  setTitle(mode=="compose"?"Send image":mode=="chat"?"Room chat":mode=="mine"?"My images":mode=="view"?"Private image":"Message images");
  content=CCNode::create();m_mainLayer->addChild(content);statusLabel=CCLabelBMFont::create("","bigFont.fnt");statusLabel->setPosition({202,17});m_mainLayer->addChild(statusLabel);reset();
  if(!appearance::validOrigin(url)){status("Set a valid HTTPS MuchoCore server URL first");return true;}if(!session()){status("Log in to use messages and images");return true;}
  if(mode=="compose"){
   label("JPG/PNG / up to 4 MB / private attachment",202,252,370,.25f);
   caption=TextInput::create(344,"Caption");caption->setFilter("");caption->setMaxCharCount(240);caption->setString(emoji::shortenText(note,240));caption->setPosition({202,83});content->addChild(caption);
   if(scope=="message"){subject=TextInput::create(244,"Subject");subject->setFilter("");subject->setMaxCharCount(40);subject->setString(emoji::shortenText(title,40));subject->setPosition({160,115});content->addChild(subject);}
   button("Choose photo",120,45,menu_selector(MediaPopup::onPick));button("Send",288,45,menu_selector(MediaPopup::onSend));
   request("config","",[this](matjson::Value const& v){images=v["images_enabled"].asBool().unwrapOr(false);auto policy=text(v,"moderation");if(!images){status("Image sending is disabled by the server owner");return;}
    status(policy=="local"?"Local AI on this server / photos never go to external APIs":policy=="manual"?"Images appear only after a server moderator approves them":"Image sending enabled / moderation disabled");
   });
  }else if(mode=="view"){
   button("-",137,83,menu_selector(MediaPopup::onZoom),-1,.28f);button("Fit",202,83,menu_selector(MediaPopup::onZoom),0,.28f);button("+",267,83,menu_selector(MediaPopup::onZoom),1,.28f);label("Report inappropriate content",202,63,330,.20f);button("NSFW",68,43,menu_selector(MediaPopup::onReport),0,.27f);button("Violence",155,43,menu_selector(MediaPopup::onReport),1,.25f);button("Child safety",258,43,menu_selector(MediaPopup::onReport),2,.24f);button("Other",350,43,menu_selector(MediaPopup::onReport),3,.26f);loadImage();
  }else{listing();reload();}return true;
 }
 static void open(std::string view,int id){if(auto p=create(std::move(view),id,"","","",{}))p->show();}
public:
 static MediaPopup* create(std::string view,int id,std::string conversation,std::string note,std::string title,std::function<void()> callback){auto p=new MediaPopup;if(p->init(std::move(view),id,std::move(conversation),std::move(note),std::move(title),std::move(callback))){p->autorelease();return p;}delete p;return nullptr;}
};
}
void compose(std::string scope,int target,std::string caption,std::string subject,std::function<void()> accepted){if(auto p=MediaPopup::create("compose",target,std::move(scope),std::move(caption),std::move(subject),std::move(accepted)))p->show();}
void showAttachments(int message){if(auto p=MediaPopup::create("attachments",message,"","","",{}))p->show();}
void showChat(int room){if(auto p=MediaPopup::create("chat",room,"","","",{}))p->show();}
void showMine(){if(auto p=MediaPopup::create("mine",0,"","","",{}))p->show();}
}

class $modify(MuchoImageWrite,GJWriteMessagePopup){
 bool init(int account,int message){if(!GJWriteMessagePopup::init(account,message))return false;
  auto b=mucho::button(ButtonSprite::create("Photo"),this,menu_selector(MuchoImageWrite::onMuchoPhoto),.32f);b->setID("mucho-message-photo");
  auto size=CCDirector::get()->getWinSize();b->setPosition(m_buttonMenu->convertToNodeSpace({size.width/2+122,size.height/2+78}));m_buttonMenu->addChild(b);return true;
 }
 void onMuchoPhoto(CCObject*){auto weak=WeakRef(this);mucho::media::compose("message",m_accountID,m_messageText.c_str(),m_subjectText.c_str(),[weak]{if(auto p=weak.lock();p&&p->isRunning())p->closeMessagePopup(true);});}
};
class $modify(MuchoImageRead,GJMessagePopup){
 bool init(GJUserMessage* message){if(!GJMessagePopup::init(message))return false;
  auto b=mucho::button(ButtonSprite::create("Images"),this,menu_selector(MuchoImageRead::onMuchoImages),.32f);b->setID("mucho-message-images");auto size=CCDirector::get()->getWinSize();b->setPosition(m_buttonMenu->convertToNodeSpace({size.width/2+120,size.height/2+78}));m_buttonMenu->addChild(b);return true;
 }
 void onMuchoImages(CCObject*){if(m_message&&m_message->m_messageID>0)mucho::media::showAttachments(m_message->m_messageID);}
};
