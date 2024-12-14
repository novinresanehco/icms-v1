import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Settings, Globe, Bell, Shield, Database } from 'lucide-react';

const SystemSettings = () => {
  const handleSave = () => {
    // Handle save functionality
    console.log('Saving settings...');
  };

  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>تنظیمات سیستم</CardTitle>
            <button 
              onClick={handleSave}
              className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
            >
              ذخیره تغییرات
            </button>
          </div>
        </CardHeader>
        
        <CardContent className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {/* General Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center mb-4">
                  <Settings className="w-5 h-5 text-gray-500 mr-2" />
                  <h3 className="font-medium">تنظیمات عمومی</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">نام سایت</label>
                    <input type="text" className="w-full border rounded p-2" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">توضیحات سایت</label>
                    <textarea className="w-full border rounded p-2" rows={3} />
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Language Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center mb-4">
                  <Globe className="w-5 h-5 text-gray-500 mr-2" />
                  <h3 className="font-medium">تنظیمات زبان</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">زبان پیش‌فرض</label>
                    <select className="w-full border rounded p-2">
                      <option>فارسی</option>
                      <option>English</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">زبان‌های فعال</label>
                    <div className="space-y-2">
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded mr-2" />
                        <span>فارسی</span>
                      </label>
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded mr-2" />
                        <span>English</span>
                      </label>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Security Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center mb-4">
                  <Shield className="w-5 h-5 text-gray-500 mr-2" />
                  <h3 className="font-medium">تنظیمات امنیتی</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">احراز هویت دو مرحله‌ای</label>
                    <div>
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded mr-2" />
                        <span>فعال‌سازی برای همه کاربران</span>
                      </label>
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">محدودیت IP</label>
                    <textarea 
                      placeholder="هر IP در یک خط"
                      className="w-full border rounded p-2" 
                      rows={3}
                    />
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Notification Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center mb-4">
                  <Bell className="w-5 h-5 text-gray-500 mr-2" />
                  <h3 className="font-medium">تنظیمات اعلان‌ها</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">اعلان‌های ایمیلی</label>
                    <div className="space-y-2">
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded mr-2" />
                        <span>محتوای جدید</span>
                      </label>
                      <label className="flex items-center">
                        <input type="checkbox" className="rounded mr-2" />
                        <span>نظرات جدید</span>
                      </label>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Database Settings */}
            <Card>
              <CardContent className="p-4">
                <div className="flex items-center mb-4">
                  <Database className="w-5 h-5 text-gray-500 mr-2" />
                  <h3 className="font-medium">تنظیمات پایگاه داده</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">بک‌آپ خودکار</label>
                    <select className="w-full border rounded p-2">
                      <option>هر روز</option>
                      <option>هر هفته</option>
                      <option>هر ماه</option>
                    </select>
                  </div>
                  <button className="w-full px-4 py-2 bg-gray-100 rounded hover:bg-gray-200">
                    ایجاد بک‌آپ دستی
                  </button>
                </div>
              </CardContent>
            </Card>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default SystemSettings;
