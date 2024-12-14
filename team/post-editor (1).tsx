import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { 
  Image, Link, List, Quote, Code, Bold, Italic, 
  AlignLeft, AlignCenter, AlignRight, Save, Eye
} from 'lucide-react';

const PostEditor = () => {
  return (
    <div className="max-w-5xl mx-auto p-6">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>ایجاد مطلب جدید</CardTitle>
            <div className="flex gap-2">
              <button className="px-4 py-2 border rounded hover:bg-gray-50 flex items-center">
                <Eye className="w-4 h-4 mr-2" />
                پیش‌نمایش
              </button>
              <button className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 flex items-center">
                <Save className="w-4 h-4 mr-2" />
                انتشار
              </button>
            </div>
          </div>
        </CardHeader>

        <CardContent className="p-6">
          <div className="space-y-6">
            {/* Title */}
            <div>
              <input
                type="text"
                placeholder="عنوان مطلب..."
                className="w-full text-3xl font-bold border-0 border-b-2 border-gray-200 focus:border-blue-500 focus:ring-0 pb-2"
              />
            </div>

            {/* Toolbar */}
            <div className="border rounded-lg p-2 flex flex-wrap gap-2">
              <div className="flex border-r pr-2">
                <button className="p-2 hover:bg-gray-100 rounded">
                  <Bold className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <Italic className="w-4 h-4" />
                </button>
              </div>
              
              <div className="flex border-r pr-2">
                <button className="p-2 hover:bg-gray-100 rounded">
                  <AlignLeft className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <AlignCenter className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <AlignRight className="w-4 h-4" />
                </button>
              </div>

              <div className="flex">
                <button className="p-2 hover:bg-gray-100 rounded">
                  <Link className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <Image className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <List className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <Quote className="w-4 h-4" />
                </button>
                <button className="p-2 hover:bg-gray-100 rounded">
                  <Code className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* Editor */}
            <div className="min-h-[400px] border rounded-lg p-4">
              <div className="prose max-w-none">
                <p className="text-gray-400">محتوای مطلب خود را اینجا بنویسید...</p>
              </div>
            </div>

            {/* Meta Info */}
            <div className="grid grid-cols-3 gap-6">
              <Card>
                <CardContent className="p-4">
                  <h3 className="font-medium mb-3">دسته‌بندی</h3>
                  <select className="w-full border rounded p-2">
                    <option>عمومی</option>
                    <option>آموزش</option>
                    <option>اخبار</option>
                  </select>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="p-4">
                  <h3 className="font-medium mb-3">برچسب‌ها</h3>
                  <input 
                    type="text" 
                    placeholder="برچسب‌ها را با کاما جدا کنید"
                    className="w-full border rounded p-2"
                  />
                </CardContent>
              </Card>

              <Card>
                <CardContent className="p-4">
                  <h3 className="font-medium mb-3">تصویر شاخص</h3>
                  <button className="w-full border rounded p-6 text-center">
                    <Image className="w-8 h-8 mx-auto mb-2 text-gray-400" />
                    <span className="text-sm text-gray-500">انتخاب تصویر</span>
                  </button>
                </CardContent>
              </Card>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default PostEditor;
