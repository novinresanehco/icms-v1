import React from 'react';
import { Alert } from '@/components/ui/alert';

const Content = ({ content, metadata }) => (
  <div className="max-w-6xl mx-auto bg-white rounded-lg shadow-sm">
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-4">{content.title}</h1>
      <div className="prose" dangerouslySetInnerHTML={{ __html: content.body }} />
      {metadata && (
        <div className="mt-4 text-sm text-gray-600 border-t pt-4">
          {Object.entries(metadata).map(([key, value]) => (
            <div key={key}>{key}: {value}</div>
          ))}
        </div>
      )}
    </div>
  </div>
);

const Media = ({ items = [] }) => (
  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
    {items.map((item, i) => (
      <div key={i} className="relative bg-gray-100 rounded-lg overflow-hidden">
        <img src={item.url} alt={item.title} className="w-full h-48 object-cover" loading="lazy" />
        <div className="absolute bottom-0 w-full p-2 bg-black/50">
          <p className="text-white text-sm">{item.title}</p>
        </div>
      </div>
    ))}
  </div>
);

const Template = ({ template, data }) => (
  <div dangerouslySetInnerHTML={{ __html: template }} />
);

export default { Content, Media, Template };
