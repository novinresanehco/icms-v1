import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Image, Type, Link, List, Quote } from 'lucide-react';

const ContentEditor = () => {
  const [activeTab, setActiveTab] = useState('edit');

  return (
    <div className="w-full max-w-4xl mx-auto">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>Content Editor</CardTitle>
            <div className="flex space-x-2">
              <button 
                className={`px-4 py-2 rounded ${
                  activeTab === 'edit' ? 'bg-blue-500 text-white' : 'bg-gray-100'
                }`}
                onClick={() => setActiveTab('edit')}
              >
                Edit
              </button>
              <button 
                className={`px-4 py-2 rounded ${
                  activeTab === 'preview' ? 'bg-blue-500 text-white' : 'bg-gray-100'
                }`}
                onClick={() => setActiveTab('preview')}
              >
                Preview
              </button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-5 gap-4">
            <div className="col-span-4">
              <div className="mb-4">
                <input
                  type="text"
                  placeholder="Enter title..."
                  className="w-full p-2 text-2xl font-bold border-b focus:outline-none focus:border-blue-500"
                />
              </div>
              <div className="min-h-[400px] border rounded p-4">
                {activeTab === 'edit' ? (
                  <div className="prose max-w-none">
                    <p>Start writing your content here...</p>
                  </div>
                ) : (
                  <div className="prose max-w-none">
                    <h1>Preview Mode</h1>
                    <p>Your content will appear here...</p>
                  </div>
                )}
              </div>
            </div>
            <div className="col-span-1 border-l pl-4">
              <div className="space-y-2">
                <div className="text-sm font-semibold mb-4">Add Elements</div>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-100 rounded">
                  <Type size={20} />
                  <span>Text</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-100 rounded">
                  <Image size={20} />
                  <span>Image</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-100 rounded">
                  <Link size={20} />
                  <span>Link</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-100 rounded">
                  <List size={20} />
                  <span>List</span>
                </button>
                <button className="w-full p-2 flex items-center space-x-2 hover:bg-gray-100 rounded">
                  <Quote size={20} />
                  <span>Quote</span>
                </button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default ContentEditor;
