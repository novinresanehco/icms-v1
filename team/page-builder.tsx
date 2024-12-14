import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { 
  Layout, Type, Image, Columns, Video, Button, Box, 
  Heading, List, Link, Plus, Settings, Eye, Save
} from 'lucide-react';

const PageBuilder = () => {
  return (
    <div className="flex h-screen bg-gray-100">
      {/* Left Sidebar - Components */}
      <div className="w-64 bg-white border-r overflow-y-auto">
        <div className="p-4">
          <h3 className="font-medium mb-4">المان‌ها</h3>
          
          <div className="space-y-2">
            <div className="text-sm text-gray-500 mb-2">ساختار</div>
            <div className="grid grid-cols-2 gap-2">
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Layout className="w-5 h-5 mb-1" />
                <span className="text-xs">بخش</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Columns className="w-5 h-5 mb-1" />
                <span className="text-xs">ستون</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Box className="w-5 h-5 mb-1" />
                <span className="text-xs">باکس</span>
              </div>
            </div>

            <div className="text-sm text-gray-500 mb-2 mt-4">محتوا</div>
            <div className="grid grid-cols-2 gap-2">
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Heading className="w-5 h-5 mb-1" />
                <span className="text-xs">سرتیتر</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Type className="w-5 h-5 mb-1" />
                <span className="text-xs">متن</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Image className="w-5 h-5 mb-1" />
                <span className="text-xs">تصویر</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Video className="w-5 h-5 mb-1" />
                <span className="text-xs">ویدیو</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <Button className="w-5 h-5 mb-1" />
                <span className="text-xs">دکمه</span>
              </div>
              <div className="border rounded p-3 hover:bg-blue-50 cursor-move flex flex-col items-center">
                <List className="w-5 h-5 mb-1" />
                <span className="text-xs">لیست</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col">
        {/* Top Bar */}
        <div className="bg-white border-b p-4 flex justify-between items-center">
          <div className="flex items-center space-x-4">
            <button className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 flex items-center">
              <Save className="w-4 h-4 mr-2" />
              ذخیره
            </button>
            <button className="px-4 py-2 border rounded hover:bg-gray-50 flex items-center">
              <Eye className="w-4 h-4 mr-2" />
              پیش‌نمایش
            </button>
          </div>
          <div>
            <select className="border rounded p-2">
              <option>دسکتاپ</option>
              <option>تبلت</option>
              <option>موبایل</option>
            </select>
          </div>
        </div>

        {/* Canvas */}
        <div className="flex-1 overflow-y-auto p-8">
          {/* Empty State */}
          <div className="border-2 border-dashed rounded-lg p-8 text-center">
            <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 text-blue-500 mb-4">
              <Plus className="w-8 h-8" />
            </div>
            <h3 className="text-lg font-medium mb-2">شروع طراحی صفحه</h3>
            <p className="text-gray-500 mb-4">المان‌های مورد نظر را به اینجا بکشید</p>
            <button className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
              افزودن بخش
            </button>
          </div>
        </div>
      </div>

      {/* Right Sidebar - Settings */}
      <div className="w-80 bg-white border-l overflow-y-auto">
        <div className="p-4">
          <h3 className="font-medium mb-4 flex items-center">
            <Settings className="w-4 h-4 mr-2" />
            تنظیمات المان
          </h3>
          
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                عرض المان
              </label>
              <select className="w-full border rounded p-2">
                <option>تمام عرض</option>
                <option>محدود به محتوا</option>
                <option>سفارشی</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                فاصله از بالا
              </label>
              <input type="range" className="w-full" />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                فاصله از پایین
              </label>
              <input type="range" className="w-full" />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                پس‌زمینه
              </label>
              <div className="grid grid-cols-2 gap-2">
                <button className="border rounded p-2 hover:bg-gray-50">رنگ</button>
                <button className="border rounded p-2 hover:bg-gray-50">تصویر</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PageBuilder;
