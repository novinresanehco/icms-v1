import React, { useState } from 'react';
import { Alert } from '@/components/ui/alert';

const Engine = ({ template, content }) => {
  const [error, setError] = useState(null);

  const Display = ({ data }) => (
    <div className="p-4 bg-white rounded-lg">
      <h1 className="text-2xl font-bold">{data.title}</h1>
      <div className="prose">{data.content}</div>
    </div>
  );

  const Media = ({ items }) => (
    <div className="grid grid-cols-3 gap-4">
      {items.map((item, i) => (
        <div key={i} className="aspect-square bg-gray-100 rounded-lg overflow-hidden">
          <img src="/api/placeholder/400/400" alt={item.alt} className="w-full h-full object-cover"/>
        </div>
      ))}
    </div>
  );

  const Layout = ({ sections }) => (
    <div className="space-y-6">
      {sections.map((section, i) => (
        <div key={i} className={section.cols ? `grid grid-cols-${section.cols} gap-4` : ''}>
          {renderContent(section)}
        </div>
      ))}
    </div>
  );

  const renderContent = (data) => {
    try {
      switch (data.type) {
        case 'content': return <Display data={data} />;
        case 'media': return <Media items={data.items} />;
        case 'layout': return <Layout sections={data.sections} />;
        default: throw new Error('Invalid type');
      }
    } catch (e) {
      setError(e.message);
      return <Alert variant="destructive">{e.message}</Alert>;
    }
  };

  return (
    <div className="w-full">
      {error ? (
        <Alert variant="destructive">{error}</Alert>
      ) : (
        renderContent(template)
      )}
    </div>
  );
};

export default Engine;
