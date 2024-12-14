import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Layout, Palette, Settings, Code, Eye, Copy } from 'lucide-react';

const TemplateManager = () => {
  const [activeTemplate, setActiveTemplate] = useState('default');
  
  const templates = [
    { id: 'default', name: 'Default Theme', status: 'active' },
    { id: 'modern', name: 'Modern Business', status: 'installed' },
    { id: 'blog', name: 'Blog Classic', status: 'installed' },
    { id: 'portfolio', name: 'Portfolio Pro', status: 'installed' }
  ];

  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex justify-between items-center">
            <CardTitle>Template Management</CardTitle>
            <button className="px-4 py-2 bg-blue-500 text-white rounded">
              Install New Template
            </button>
          </div>
        </CardHeader>
        
        <CardContent>
          <div className="flex gap-6">
            {/* Template List */}
            <div className="w-1/3 border-r pr-6">
              <div className="space-y-3">
                {templates.map(template => (
                  <div 
                    key={template.id}
                    className={`p-3 rounded border cursor-pointer transition-colors
                      ${activeTemplate === template.id ? 'border-blue-500 bg-blue-50' : 'hover:bg-gray-50'}`}
                    onClick={() => setActiveTemplate(template.id)}
                  >
                    <div className="flex justify-between items-center">
                      <div>
                        <h3 className="font-medium">{template.name}</h3>
                        <span className="text-sm text-gray-500 capitalize">{template.status}</span>
                      </div>
                      <Layout className="w-5 h-5 text-gray-400" />
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Template Settings */}
            <div className="flex-1">
              <div className="space-y-6">
                {/* Preview */}
                <div className="aspect-video bg-gray-100 rounded flex items-center justify-center border">
                  <div className="text-gray-400 flex items-center">
                    <Eye className="w-5 h-5 mr-2" />
                    Template Preview
                  </div>
                </div>

                {/* Settings Tabs */}
                <div className="border rounded">
                  <div className="flex border-b">
                    <button className="px-4 py-2 border-b-2 border-blue-500 text-blue-500">General</button>
                    <button className="px-4 py-2 text-gray-500">Styles</button>
                    <button className="px-4 py-2 text-gray-500">Layout</button>
                    <button className="px-4 py-2 text-gray-500">Advanced</button>
                  </div>
                  
                  <div className="p-4 space-y-4">
                    {/* Settings Options */}
                    <div className="grid grid-cols-2 gap-4">
                      <Card>
                        <CardContent className="p-4">
                          <div className="flex items-center space-x-2 mb-4">
                            <Palette className="w-4 h-4" />
                            <span className="font-medium">Color Scheme</span>
                          </div>
                          <div className="flex space-x-2">
                            <div className="w-6 h-6 rounded-full bg-blue-500"></div>
                            <div className="w-6 h-6 rounded-full bg-green-500"></div>
                            <div className="w-6 h-6 rounded-full bg-purple-500"></div>
                            <div className="w-6 h-6 rounded-full bg-gray-500"></div>
                          </div>
                        </CardContent>
                      </Card>

                      <Card>
                        <CardContent className="p-4">
                          <div className="flex items-center space-x-2 mb-4">
                            <Settings className="w-4 h-4" />
                            <span className="font-medium">Typography</span>
                          </div>
                          <select className="w-full p-2 border rounded">
                            <option>Inter</option>
                            <option>Roboto</option>
                            <option>Poppins</option>
                          </select>
                        </CardContent>
                      </Card>

                      <Card>
                        <CardContent className="p-4">
                          <div className="flex items-center space-x-2 mb-4">
                            <Code className="w-4 h-4" />
                            <span className="font-medium">Custom CSS</span>
                          </div>
                          <button className="text-blue-500 text-sm">Edit Custom CSS</button>
                        </CardContent>
                      </Card>

                      <Card>
                        <CardContent className="p-4">
                          <div className="flex items-center space-x-2 mb-4">
                            <Copy className="w-4 h-4" />
                            <span className="font-medium">Template Backup</span>
                          </div>
                          <button className="text-blue-500 text-sm">Create Backup</button>
                        </CardContent>
                      </Card>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default TemplateManager;
