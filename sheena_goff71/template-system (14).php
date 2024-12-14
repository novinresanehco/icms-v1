import React from 'react';
import { Alert } from '@/components/ui/alert';

const TemplateSystem = {
  Engine: ({ template, data }) => (
    <div className="template-root" dangerouslySetInnerHTML={{ 
      __html: sanitizeAndRender(template, data) 
    }} />
  ),

  Content: ({ content }) => (
    <div className="max-w-6xl mx-auto">
      <h1 className="text-2xl font-bold">{content.title}</h1>
      <div className="prose" dangerouslySetInnerHTML={{ 
        __html: content.body 
      }} />
    </div>
  ),

  Media: ({ items = [] }) => (
    <div className="grid grid-cols-3 gap-4">
      {items.map((item, i) => (
        <div key={i} className="relative rounded-lg overflow-hidden">
          <img src={item.url} alt={item.title} className="h-48 w-full object-cover" />
          <div className="absolute bottom-0 p-2 bg-black/50 w-full">
            <p className="text-white text-sm">{item.title}</p>
          </div>
        </div>
      ))}
    </div>
  ),

  Components: {
    Card: ({ title, children }) => (
      <div className="bg-white rounded-lg shadow-sm p-4">
        {title && <h3 className="text-lg font-bold mb-2">{title}</h3>}
        {children}
      </div>
    ),
    Alert
  }
};

export default TemplateSystem;
