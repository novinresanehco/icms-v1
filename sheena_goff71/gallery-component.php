const MediaGallery = ({ items, layout = 'grid', onSelect }) => {
  const [activeIndex, setActiveIndex] = useState(0);

  const handleSelect = (index) => {
    setActiveIndex(index);
    onSelect?.(items[index]);
  };

  return (
    <div className={`gallery ${layout}`}>
      <div className="grid grid-cols-3 gap-4">
        {items.map((item, index) => (
          <div key={item.id} 
               className="aspect-square overflow-hidden rounded-lg shadow-lg cursor-pointer"
               onClick={() => handleSelect(index)}>
            <img src={item.url}
                 alt={item.title}
                 className="w-full h-full object-cover transition-transform hover:scale-105"
                 loading="lazy" />
          </div>
        ))}
      </div>
      {layout === 'slider' && (
        <div className="mt-4 flex justify-center gap-2">
          {items.map((_, index) => (
            <button
              key={index}
              className={`w-2 h-2 rounded-full ${index === activeIndex ? 'bg-blue-500' : 'bg-gray-300'}`}
              onClick={() => handleSelect(index)}
            />
          ))}
        </div>
      )}
    </div>
  );
};

export default MediaGallery;
