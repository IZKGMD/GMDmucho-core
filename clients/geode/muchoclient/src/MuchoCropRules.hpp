#pragma once
#include <algorithm>
#include <cmath>

namespace mucho::profile {
    struct CropRegion { double width, height, left, top, imageWidth, imageHeight; };
    // Same pixel rounding and top-left origin as ProfileImage::render on the server.
    inline CropRegion cropRegion(double w,double h,int x,int y,int zoom,int rotation,double outW=960,double outH=320) {
        if (rotation == 90 || rotation == 270) std::swap(w,h);
        auto scale = std::max(outW/w,outH/h)*(std::clamp(zoom,100,300)/100.0);
        double cw = std::min(w,std::round(outW/scale)), ch = std::min(h,std::round(outH/scale));
        return {cw,ch,std::round((w-cw)*(std::clamp(x,0,1000)/1000.0)),
            std::round((h-ch)*(std::clamp(y,0,1000)/1000.0)),w,h};
    }
    inline int cropPosition(double position,double slack) {
        return slack <= 0 ? 500 : static_cast<int>(std::round(std::clamp(position/slack,0.0,1.0)*1000));
    }
    inline int cropZoom(double baseWidth,double requestedWidth) {
        return static_cast<int>(std::round(std::clamp(baseWidth/std::max(1e-9,requestedWidth)*100,100.0,300.0)));
    }
    struct CropPosition { int x, y; };
    // Preserve the selected source point through zoom/clockwise quarter turns.
    // Clamp only when the new rectangle would extend outside the photo.
    inline CropPosition focusedCrop(double w,double h,int x,int y,int zoom,int rotation,
        int nextZoom,int nextRotation,double outW=960,double outH=320) {
        auto old = cropRegion(w,h,x,y,zoom,rotation,outW,outH);
        double cx = old.left+old.width/2, cy = old.top+old.height/2;
        double rw = old.imageWidth, rh = old.imageHeight;
        for (int turns=((nextRotation-rotation+360)%360)/90; turns>0; --turns) {
            double nextX = rh-cy; cy=cx; cx=nextX; std::swap(rw,rh);
        }
        auto next = cropRegion(w,h,500,500,nextZoom,nextRotation,outW,outH);
        return {cropPosition(cx-next.width/2,next.imageWidth-next.width),
            cropPosition(cy-next.height/2,next.imageHeight-next.height)};
    }
    // Move by image pixels rather than a fraction of the remaining slack, so
    // arrow-key steps stay useful even when the crop nearly fills the photo.
    inline CropPosition movedCrop(CropRegion const& region,double dx,double dy) {
        return {cropPosition(region.left+dx,region.imageWidth-region.width),
            cropPosition(region.top+dy,region.imageHeight-region.height)};
    }
}
